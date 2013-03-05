<?php

/**
 * A ShellCommand is an abstraction that allows for a specification of a complex series of inputs, shell commands, and outputs.
 *
 * ShellCommand's can then be run locally or remotely. You can send a ShellCommand to any compatible ShellCommand server that can run the commands you're sending it and you're set.
 *
 * A ShellCommand is capable of downloading one or more input files,
 * running one or many shell commands, sending the resulting files to
 * specified paths, and hitting result webhook(s).
 *
 * ShellCommand:
 *   inputs:
 *     'duck'  => "http://www.input.com/duck.jpg"
 *     'goose' => "http://www.input.com/goose.jpg"
 *   outputs:
 *     'duckLarge'  => "s3://www.output.com/duck-1500x1000.jpg"
 *     'duckSmall'  => "s3://www.output.com/duck-666x500.jpg"
 *     'gooseLarge' => "http://www.output.com/my/web/service"
 *     'gooseSmall' => "http://www.output.com/my/web/service"
 *     'dimensions' => "capture://dimensions"
 *   commands:
 *     - "convert %%inputs.duck%%  -resize 1500x1000 %%outputs.duckLarge%%"
 *     - "convert %%inputs.duck%%  -resize 666x500   %%outputs.duckSmall%%"
 *     - "convert %%inputs.goose%% -resize 1500x1000 %%outputs.gooseLarge%%"
 *     - "convert %%inputs.goose%% -resize 666x500   %%outputs.gooseSmall%%"
 *     - "identify %%inputs.duck%% > %%outputs.dimensions%%"
 *   notifications:
 *     - "http://www.notification.com/receive/webhook/1234"
 */
class ShellCommand
{
    protected $inputs;
    protected $commands;
    protected $outputs;
    protected $notifications;
    protected $custom_data;

    private static $serializationFields = array('inputs','commands','outputs','notifications','custom_data');

    public function __construct()
    {
        $this->inputs = array();
        $this->outputs = array();
        $this->commands = array();
        $this->notifications = array();
        $this->custom_data = array();
    }

    public static function create()
    {
        return new ShellCommand();
    }
    public static function createFromJSON($json)
    {
        return static::create()->fromJSON($json);
    }

    public function addInput($name, $value)
    {
        $this->inputs[$name] = $value;
        return $this;
    }

    public function getInputs()
    {
        return $this->inputs;
    }

    public function addCommand($command)
    {
        $this->commands[] = $command;
        return $this;
    }

    public function getCommands()
    {
      return $this->commands;
    }

    public function addOutput($name, $value)
    {
        $this->outputs[$name] = $value;
        return $this;
    }

    public function getOutputs()
    {
        return $this->outputs;
    }

    public function addNotification($url)
    {
        $this->notifications[] = $url;
        $this->notifications = array_unique($this->notifications);
        return $this;
    }

    public function getNotifications()
    {
      return $this->notifications;
    }

    public function setCustomData($data)
    {
        $this->custom_data = $data;
        return $this;
    }

    public function getCustomData()
    {
        return $this->custom_data;
    }

    public function toJSON()
    {
        $data = array();
        foreach (self::$serializationFields as $f) {
            $data[$f] = $this->$f;
        }
        return json_encode($data);
    }

    public function fromJSON($json)
    {
        $data = json_decode($json, true);
        if ($data === false) throw new Exception("invalid JSON: {$json}");

        foreach (self::$serializationFields as $f) {
            if (isset($data[$f]))
            {
                $this->$f = $data[$f];
            }
        }

        return $this;
    }

    public function __toString()
    {
        return $this->toJSON();
    }
}
