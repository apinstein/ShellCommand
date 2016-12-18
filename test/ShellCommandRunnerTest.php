<?php

class ShellCommandRunnerTest extends PHPUnit_Framework_TestCase
{
  function test1()
  {
    $sc = ShellCommand::create()
      ->setCustomData('foo')
      ->addCommand("echo 'HI' > %%outputs.echo%%")
      ->addOutput('echo', 'capture://echo')
      ->addNotification('http://foo.com/bar')
      ;

    $nfRan = false;
    $that = $this;
    $nF = function($notificationUrl, $responseData) use (&$nfRan, $that) {
      $nfRan = true;
      $that->assertEquals('http://foo.com/bar', $notificationUrl);
    };

    $response = ShellCommandRunner::create($sc, array('notificationRunner' => $nF))->run();

    $this->assertEquals(ShellCommandRunner::STATUS_SUCCESS, $response['status']);
    $this->assertNull($response['error']);
    $this->assertEquals("HI\n", $response['capture']['echo']);
    $this->assertEquals("foo", $response['customData']);
    $this->assertTrue($nfRan, "Notification callback didn't run.");
  }

  function testHttpInputScheme()
  {
    $scr = ShellCommandRunner::create(ShellCommand::create());
    $tempFile = $scr->processInput("http://edition.cnn.com");
    $this->assertTrue(file_exists($tempFile));
  }

  function testInputUrlRewriting()
  {
      $sampleFilePath = 'file://' . __DIR__ . '/sample.txt';
      $scr = ShellCommandRunner::create(ShellCommand::create(), array(
          'inputUrlRewriter' => function($input) use ($sampleFilePath) {
              return $sampleFilePath;
          }));
    $tempFile = $scr->processInput("http://edition.cnn.com");
    $this->assertEquals(file_get_contents($sampleFilePath), file_get_contents($tempFile));
  }

  function testHttpInputThrowsExcepton()
  {
    $scr = ShellCommandRunner::create(ShellCommand::create());
    $exceptionCaught = false;
    try {
      $tempFile = $scr->processInput("http://www.asdfasdflajsd;flkajsdlkjfs.com");
    }
    catch (Exception $e)
    {
      $exceptionCaught = true;
    }
    $this->assertTrue($exceptionCaught);
  }

  function testFileInputSceme()
  {
    $scr = ShellCommandRunner::create(ShellCommand::create());
    $sourceFile = $scr->generateTempfile('input-');
    $tempFile = $scr->processInput($sourceFile);
    $this->assertTrue(file_exists($tempFile));
    $sourceFile = "file://" . $scr->generateTempfile('input-');
    $tempFile = $scr->processInput($sourceFile);
    $this->assertTrue(file_exists($tempFile));
  }

  /**
   * @dataProvider extensionsDataProvider
   */
  function testInputTempFilesPreserveExtensions($expectedExtension)
  {
    $extensionWithDot = $expectedExtension ? ".{$expectedExtension}" : NULL;
    $fileName = "temp{$extensionWithDot}";
    touch("/tmp/{$fileName}");

    $sc = ShellCommand::create()
      ->addInput('inputExtension', "file:///tmp/{$fileName}")
      ->addCommand("echo '%%inputs.inputExtension%%' > %%outputs.outputExtension%%")
      ->addOutput('outputExtension', "capture://{$fileName}")
      ;
    $response = ShellCommandRunner::create($sc)->run();
    $extensionOnTempFile = pathinfo(trim($response['capture'][$fileName]), PATHINFO_EXTENSION);
    $this->assertEquals($expectedExtension, $extensionOnTempFile, "Input tempfile extension didn't match input URL.");
  }

  function testOutputUrlRewriting()
  {
    $expectedSampleOutput = 'sampleoutput';

    $sc = ShellCommand::create()
      ->addCommand("env echo -n '{$expectedSampleOutput}' > %%outputs.myOutput%%")
      ->addOutput('myOutput', "capture://myOutput")
      ;

    $overrideOutputToLocal = 'file://' . tempnam(sys_get_temp_dir(), 'ShellCommandRunnerTest_');
    $scr = ShellCommandRunner::create($sc, array(
      'outputUrlRewriter' => function($input) use ($overrideOutputToLocal) {
        return $overrideOutputToLocal;
      }));
    $scr->run();
    $this->assertEquals($expectedSampleOutput, file_get_contents($overrideOutputToLocal));
  }

  /**
   * @dataProvider extensionsDataProvider
   */
  function testOutputTempFilesPreserveExtensions($expectedExtension)
  {
    $extensionWithDot = $expectedExtension ? ".{$expectedExtension}" : NULL;
    $fileName = "temp{$extensionWithDot}";

    $sc = ShellCommand::create()
      ->addCommand("echo '%%outputs.expectExtension%%' > %%outputs.expectExtension%%")
      ->addOutput('expectExtension', "capture://{$fileName}")
      ;
    $response = ShellCommandRunner::create($sc)->run();
    $extensionOnTempFile = pathinfo(trim($response['capture'][$fileName]), PATHINFO_EXTENSION);
    $this->assertEquals($expectedExtension, $extensionOnTempFile, "Output tempfile extension didn't match output URL.");
  }

  function extensionsDataProvider()
  {
    return array(
      array(''),
      array('jpg'),
      array('tiff'),
    );
  }

  /**
   * @testdox ShellCommandRunner is able to upload assets to S3.
   */
  public function testS3UploaderIntegration()
  {
      $s3Key       = 'publicKey';
      $s3SecretKey = 'secretKey';
      $bucket      = 'some.bucket.tourbuzz.net';
      $targetPath  = 'some/path/echo.txt';

      $shellCommand = ShellCommand::create()
          ->setCustomData('foo')
          ->addCommand("echo 'HI' > %%outputs.echo%%")
          ->addOutput("echo", "s3://{$bucket}/{$targetPath}")
      ;

      $s3Uploader = $this->getMock('S3UploaderInterface');

      // setCredentials() method
      $s3Uploader
          // Expected to be called only once.
          ->expects($this->once())
          ->method('setCredentials')
          // Expects setCredentials method to be invoked exactly with the s3Key and s3SecretKey
          ->with(
              $this->equalTo($s3Key),
              $this->equalTo($s3SecretKey)
          )
          // This method is 'fluid', and should return a reference to the object it belongs to (aka 'return $this;')
          ->will($this->returnValue($s3Uploader))
      ;

      // setRegion() method
      $s3Uploader
          // Expected to be called only once.
          ->expects($this->once())
          ->method('setRegion')
          // Expects setRegion method to be invoked with a non null value.
          ->with(
              $this->logicalNot($this->equalTo(null))
          )
          // This method is 'fluid', and should return a reference to the object it belongs to (aka 'return $this;')
          ->will($this->returnValue($s3Uploader))
      ;

      // putObject() method
      $s3Uploader
          // Expected to be called only once.
          ->expects($this->once())
          ->method('putObject')
          // Expects putObject method to be invoked exactly with sourceFilePath, bucket and targetPath
          ->with(
            // Since the generated local file path is non-deterministic, we can only assert that
            // at least is not null
            $this->logicalNot($this->equalTo(null)),
            $this->equalTo($bucket),
            $this->equalTo($targetPath)
          )
      ;

      $options = [
          's3Key'       => $s3Key,
          's3SecretKey' => $s3SecretKey,
      ];

      ShellCommandRunner::create(
          $shellCommand,
          $options,
          $s3Uploader
      )->run();
  }
}
