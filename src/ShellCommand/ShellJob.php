<?php

/**
 * A ShellJob is capable of downloading one or more input files,
 * running one or many shell commands, sending the resulting files to
 * specified paths, and hitting result webhook(s).
 *
 * ShellJob:
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
class ShellJob implements JQJob
{

  // User-specified inputs
  private $_inputs        = NULL; // The hash of input identifiers => URLs
  private $_outputs       = NULL; // The hash of output identifiers => URLs
  private $_commands      = NULL; // The array of commands to run
  private $_notifications = NULL; // The array of notification URLs to hit when
                                  // finished (regardless of success or failure)
  private $_capture = array();    // Capture data to be returned as part of each
                                  // notification. See output scheme capture://
  
  const TMP_DIR = '/tmp';

  public function __construct($jobSpec)
  {
    // Set up class variables
    foreach (array('inputs', 'outputs', 'commands', 'notifications') as $k)
    {
      $classVar        = "_{$k}";
      $this->$classVar = isset($jobSpec[$k]) ? $jobSpec[$k] : array();
    }
  }

  public function run(JQManagedJob $mJob)
  {
    $this->_log('Running shell command job.');

    // Download input files, error on non-2XX HTTP code
    // Index inputs hash by appropriate key (e.g. array('inputFile1' => '/tmp/foobar.jpg'))
    $inputs = array();
    foreach ($this->_inputs as $key => $url)
    {
      $localFilePath = $this->_downloadFile($url);
      $inputs[$key]  = $localFilePath;
    }

    // Index outputs hash with appropriate key (e.g. array('outputFile1' => '/tmp/local/file'))
    $outputs = array();
    foreach ($this->_outputs as $key => $url)
    {
      $localFilePath = tempnam($this->_getTempPath(), 'shell-command-output');
      $outputs[$key] = $localFilePath;
    }

    // Compose commands using inputs and outputs hashes; error on insufficient information
    $commands = array();
    foreach ($this->_commands as $command)
    {
      $cmd = $command;

      // Substitute inputs
      foreach ($inputs as $key => $localPath)
      {
        $cmd = str_replace("%%inputs.{$key}%%", $localPath, $cmd);
      }

      // Substitute outputs
      foreach ($outputs as $key => $localPath)
      {
        $cmd = str_replace("%%outputs.{$key}%%", $localPath, $cmd);
      }

      array_push($commands, $cmd);
    }

    // Run commands, error on non-zero return code
    foreach ($commands as $command)
    {
      $this->_log("Running command {$command}");
      $result = exec($command, $output, $returnCode);
      if ($returnCode !== 0) throw new Exception("When running command '{$command}', encountered error: '" . var_export($output, true) . "', produced return code '{$returnCode}'.");
    }

    // PUT output files, error on non-2XX HTTP code
    foreach ($outputs as $key => $localPath)
    {
      $targetPath = $this->_outputs[$key];
      $this->_uploadFile($key, $localPath, $targetPath);
    }

    // Delete input & output files
    $allFiles = array_merge(array_values($inputs), array_values($outputs));
    foreach ($allFiles as $localFile)
    {
      if (file_exists($localFile)) unlink($localFile);
    }

    // Hit notification webhooks, error on non-2XX HTTP code
    $this->_sendNotifications(array('status' => 'success'));

    // Tell jqjobs we're finished
    return JQManagedJob::STATUS_COMPLETED;
  }

  /**
   * Downloads the file at the given URL and returns the temp
   * location where the file is stored locally.
   *
   * @param string The URL of the file to download.
   * @return string The local temp file path where the downloaded file is stored.
   * @throws Exception If the HTTP code of the download response is non-2XX.
   */
  private function _downloadFile($url)
  {
    // Generate local (tmp) path to store the downloaded file
    $extension = pathinfo($url, PATHINFO_EXTENSION);
    $tmpFile   = tempnam($this->_getTempPath(), 'shell-command-input');
    unlink($tmpFile); // We don't need the actual tmpfile, just the one with the extension
    $filePath  = "{$tmpFile}.{$extension}";

    // Download the file
    $fileHandle = fopen($filePath, 'w');
    $curlHandle = curl_init();
    curl_setopt($curlHandle, CURLOPT_FILE,            $fileHandle);
    curl_setopt($curlHandle, CURLOPT_URL,             $url);
    // curl_setopt($curlHandle, CURLOPT_HEADER,          false);
    // curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER,  true);
    $output = curl_exec($curlHandle);

    // Get the results
    $httpCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);

    // Close cURL
    curl_close($curlHandle);
    fclose($fileHandle);

    // Check the return code
    if ($httpCode >= 300)
    {
      $message = "Error downloading file from {$url} to {$filePath} due to error: " . var_export($output, true) . " and error code '{$httpCode}'.";
      $this->_log($message);
      $e = new Exception($message);
      throw $e;
    }

    // Return the local path of the downloaded file
    return $filePath;
  }

  /**
   * Uploads the file at the given local file path to the target URL.
   *
   * @param string The local file path of the file to upload.
   * @param string The target URL where we should post the file.
   * @throws Exception If the HTTP code of the upload response is non-2XX.
   */
  private function _uploadFile($key, $localFilePath, $targetUrl)
  {
    if (!file_exists($localFilePath)) throw new Exception("Could not find file at path '{$localFilePath}' for upload.");

    // Don't upload if the target url is /dev/null
    if ($targetUrl == '/dev/null')
    {
      $this->_log("Skipping uploading {$key} because the target URL is /dev/null.");
      return;
    }

    // We can handle a few different types of outputs:
    // s3://my.bucket/path/to/key.extension
    // http://my.domain/path - HTTP POST
    // capture://key - Include contents in notifications as $_POST['capture']['key']
    $url    = parse_url($targetUrl);
    $scheme = strtolower($url['scheme']);
    switch ($scheme)
    {
      case 's3':
        $this->_uploadToS3($localFilePath, $targetUrl);
        break;
      case 'http':
        $this->_uploadHTTP($localFilePath, $targetUrl);
        break;
      case 'capture':
        $this->_writeToCaptureData($localFilePath, $targetUrl);
        break;
      default:
        throw new Exception("Invalid output scheme '{$scheme}' for output key {$key}.");
    }
  }

  private function _uploadToS3($localFilePath, $targetUrl)
  {
    $creds = array('key' => AWS_KEY, 'secret' => AWS_SECRET_KEY);

    // Gather info
    $urlParts = parse_url($targetUrl);
    $bucket   = $urlParts['host'];
    $path     = preg_replace('/^\//', '', $urlParts['path']);
    $headers  = array(
      'fileUpload' => $localFilePath,
      'headers'    => array(),
    );

    // Upload!
    $s3       = new AmazonS3($creds);
    $response = $s3->create_object($bucket, $path, $headers);
    if (!$response->isOK())
    {
      throw new Exception("Upload to S3 {$targetUrl} failed.");
    }
  }

  private function _uploadHTTP($localFilePath, $targetUrl)
  {
    // Push the file
    $appRoot = APP_ROOT;
    $cmd     = "curl -T {$localFilePath} \"{$targetUrl}\"";
    $this->_log("Uploading output file from {$localFilePath} to {$targetUrl}");
    exec($cmd, $output, $retval);

    // Check the return code
    if ($retval !== 0)
    {
      $message = "Error uploading file from {$localFilePath} to {$targetUrl} due to error: " . var_export($output, true) . " and error code '{$retval}'.";
      $this->_log($message);
      $e = new Exception($message);
      throw $e;
    }
  }

  private function _writeToCaptureData($localFilePath, $targetUrl)
  {
    if (!file_exists($localFilePath)) return;

    $url  = parse_url($targetUrl);
    $host = strtolower($url['host']);
    $this->_capture[$host] = file_get_contents($localFilePath);
  }

  /**
   * Send status notifications for this job to each notification url.
   *
   * @param array The hash of data to send as the notification.
   * @throws Exception If the HTTP code of the notification response is non-2XX.
   */
  private function _sendNotifications($data)
  {
    // Don't do anything if we don't have any notifications
    if (!$this->_notifications) return;

    // Add capture data
    $data['capture'] = $this->_capture;

    // Send
    $this->_log("Sending " . count($this->_notifications) . " notifications with data: " . var_export($data, true) . ".");
    foreach ($this->_notifications as $url)
    {
      $this->_enqueueNotification($data, $url);
    }
  }
  private function _enqueueNotification($data, $url)
  {
    // Create the job
    $job = new NotificationJob($url, $data);

    // Enqueue the job
    JobsApp::getJQStore()->enqueue($job, array('maxAttempts' => 7, 'queueName' => 'notification', 'maxRuntimeSeconds' => 60));
  }

  private function _getTempPath()
  {
    $tmpDir = self::TMP_DIR;
    $tmpDir = "{$tmpDir}/shell_command_tmp";
    if (!is_dir($tmpDir)) exec("mkdir -p {$tmpDir}");
    return $tmpDir;
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
      if ($mJob->getStatus() === JQManagedJob::STATUS_FAILED)
      {
          $this->_log("Job dead for good... {$message}");
          $this->_sendNotifications(array(
            'status'  => 'failure',
            'message' => $message
          ));
      }
  }

  public function coalesceId()
  {
    return NULL;
  }

  public function description()
  {
    return "Shell job";
  }

  public function __sleep()
  {
    return array(
      '_inputs',
      '_outputs',
      '_commands',
      '_notifications',
    );
  }

  public function __toHtml()
  {
      $className = get_class($this);
      return "<h3>{$className}</h3><pre>{$this}</pre>";
  }
}

