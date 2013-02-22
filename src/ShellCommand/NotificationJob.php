<?php

/**
 * A notification job sends a hash of data to a given URL.
 *
 * For example:
 * $url = 'http://www.example.com/webhook/notify';
 * $data = array(
 *   'status'  => 'error',
 *   'message' => 'Syntax error on line 17',
 * );
 * $n = new NotificationJob($url, $data);
 */
class NotificationJob implements JQJob
{

  // User-specified inputs
  private $_url  = NULL;    // The URL of the webhook to hit
  private $_data = array(); // An array of POST data to send

  public function __construct($url, $data)
  {
    if (!$url) throw new Exception("Expected a valid URL.");
    $this->_url = $url;

    if (!is_array($data)) throw new Exception("Expected an array of data.");
    $this->_data = $data;

    $this->_log("Constructed notification job.");
  }

  public function run(JQManagedJob $mJob)
  {
    try {
      $this->_run($mJob);
    } catch (Exception $e) {
      $this->_log("Failed notification job: {$e->getMessage()}.");
      throw $e;
    }

    return JQManagedJob::STATUS_COMPLETED;
  }

  private function _run($mJob)
  {
      // Hit the webhook
      $curlHandle = curl_init();
      curl_setopt($curlHandle, CURLOPT_URL,            $this->_url);
      curl_setopt($curlHandle, CURLOPT_POST,           1);
      curl_setopt($curlHandle, CURLOPT_POSTFIELDS,     json_encode($this->_data));
      curl_setopt($curlHandle, CURLOPT_HEADER,         0);
      curl_setopt($curlHandle, CURLOPT_HTTPHEADER,     array('Content-Type: application/json'));
      curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
      $output = curl_exec($curlHandle);

      // Get the results
      $httpCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);

      // Close cURL
      curl_close($curlHandle);

      // Check the return code
      if ($httpCode >= 300)
      {
        $message = "Error hitting webhook at {$this->_url} due to error: " . var_export($output, true) . " and error code {$httpCode}.";
        throw new Exception($message);
      }
  }

  private function _log($status)
  {
    print("{$status}\n");
  }

  public function cleanup()
  {
  }

  public function statusDidChange(JQManagedJob $mJob, $oldStatus, $message)
  {
  }

  public function coalesceId()
  {
    return NULL;
  }

  public function description()
  {
    return "Notification job";
  }

  public function __sleep()
  {
    return array(
      '_url',
      '_data',
    );
  }

  public function __toHtml()
  {
      $className = get_class($this);
      return "<h3>{$className}</h3><pre>{$this}</pre>";
  }
}

