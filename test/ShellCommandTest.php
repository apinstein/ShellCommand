<?php

class ShellCommandTest extends PHPUnit_Framework_TestCase
{
    function testToJSON()
    {
        $expectedJson  =  json_encode(array(
            'inputs'        => array( 'foo' => 'http://bar.com', 'baz' => 'http://bar.com' ),
            'commands'      => array( 'convert foo bar', 'convert baz boo'),
            'outputs'       => array( 'bar' => 's3://bucket.com/path/to/bar', 'boo' => 's3://bucket.com/path/to/boo' ),
            'notifications' => array( 'http://ping.com/foo', 'http:ping.com/bar' ),
            'custom_data'   => array( 'foo' => 'bar' ),
        ));
        $j = ShellCommand::create()
            ->addInput('foo', 'http://bar.com')
            ->addInput('baz', 'http://bar.com')
            ->addCommand('convert foo bar')
            ->addCommand('convert baz boo')
            ->addOutput('bar', 's3://bucket.com/path/to/bar')
            ->addOutput('boo', 's3://bucket.com/path/to/boo')
            ->addNotification('http://ping.com/foo')
            ->addNotification('http:ping.com/bar')
            ->setCustomData(array('foo' => 'bar'))
            ;

        $json = $j->toJSON();
        $this->assertEquals($expectedJson, $json);
    }
    function testRoundTrip()
    {
        $j = ShellCommand::create()
            ->addInput('foo', 'http://bar.com')
            ->addInput('baz', 'http://bar.com')
            ->addCommand('convert foo bar')
            ->addCommand('convert baz boo')
            ->addOutput('bar', 's3://bucket.com/path/to/bar')
            ->addOutput('boo', 's3://bucket.com/path/to/boo')
            ->setCustomData('custom string')
            ;

        $originalJSON = $j->toJSON();
        $restoredObj = ShellCommand::createFromJSON($originalJSON);
        $roundTripJSON = $restoredObj->toJSON();
        $this->assertEquals($originalJSON, $roundTripJSON);
    }
    function testNotificationURLsAreDeDuplicated()
    {
      $notifications = array(
        'http://foo.com/bar',
        'http://foo.com/baz',
        'http://foo.com/bum',
      );

      // normal adds work...
      $j = ShellCommand::create();
      foreach ($notifications as $n) {
        $j->addNotification($n);
      }
      $this->assertEquals($notifications, $j->getNotifications(), "Notifications were not added as expected.");

      // duplicate adds should be ignored...
      foreach ($notifications as $n) {
        $j->addNotification($n);
      }
      $this->assertEquals($notifications, $j->getNotifications(), "Duplicate notifications where not ignored.");
    }
}
