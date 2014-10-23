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

  function testHttpInputSceme()
  {
    $scr = ShellCommandRunner::create(ShellCommand::create());
    $tempFile = $scr->processInput("http://www.cnn.com");
    $this->assertTrue(file_exists($tempFile));
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
}
