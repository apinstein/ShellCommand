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

    $response = ShellCommandRunner::create($sc, $nF)->run();

    $this->assertEquals(ShellCommandRunner::STATUS_SUCCESS, $response['status']);
    $this->assertNull($response['errorMessage']);
    $this->assertEquals("HI\n", $response['capture']['echo']);
    $this->assertEquals("foo", $response['customData']);
    $this->assertTrue($nfRan, "Notification callback didn't run.");
  }
}
