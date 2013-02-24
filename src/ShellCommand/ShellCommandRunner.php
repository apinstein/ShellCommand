<?php
class ShellCommandRunner
{
  const STATUS_SUCCESS = 'success';
  const STATUS_FAILURE = 'failure';

  protected $shellCommand       = NULL;
  protected $inputTempFiles     = array();
  protected $outputTempFiles    = array();

  protected $notificationRunner = NULL;
  protected $s3Key = NULL;
  protected $s3SecretKey = NULL;

  // Capture data to be returned as part of each
  // notification. See output scheme capture://
  protected $capture = array();
  
  /**
   * @param object ShellCommand
   * @param array Options hash:
   *              - notificationRunner:
   *                 callable (void) notificationRunnerF($url, $responseData)
   *                 responseData:
   *                     status: success | failure
   *                    capture: [captured data hash]
   *                     custom: [custom data hash]
   *              - s3Key
   *              - s3SecretKey
   */
  public function __construct(ShellCommand $shellCommand, $options = array())
  {
    $this->shellCommand       = $shellCommand;
    $this->notificationRunner = isset($options['notificationRunner']) ? $options['notificationRunner'] : NULL;
    $this->s3Key              = isset($options['s3Key']) ? $options['s3Key'] : NULL;
    $this->s3SecretKey        = isset($options['s3SecretKey']) ? $options['s3SecretKey'] : NULL;
  }

  public static function create(ShellCommand $sc, $options = array())
  {
    return new ShellCommandRunner($sc, $options);
  }

  public function run()
  {
    $status = self::STATUS_FAILURE;
    $error = "DID NOT EXECUTE PROPERLY";

    try {
      $this->processInputs();
      $this->prepareOutputs();
      $this->runCommands();
      $this->processOutputs();

      $status = self::STATUS_SUCCESS;
      $error = NULL;
    } catch (Exception $e) {
      $status = self::STATUS_FAILURE;
      $error = $e->getMessage();
    }

    // finally
    $this->cleanupTempFiles();

    $resultData = array(
      'status'     => $status,
      'error'      => ($status === self::STATUS_FAILURE ? $error : NULL),
      'capture'    => $this->capture,
      'customData' => $this->shellCommand->getCustomData()
    );

    $this->sendNotifications($resultData);

    return $resultData;
  }

  /**
   * Create inputs hash by appropriate key (e.g. array('inputFile1' => '/tmp/foobar.jpg'))
   */
  private function processInputs()
  {
    $this->inputTempFiles = array();
    foreach ($this->shellCommand->getInputs() as $key => $url)
    {
      $this->inputTempFiles[$key] = $this->processInput($url);
    }
  }

  /**
   * Create ouputs hash by appropriate key (e.g. array('outputFile1' => '/tmp/foobar.jpg'))
   */
  private function prepareOutputs()
  {
    // Index outputs hash with appropriate key (e.g. array('outputFile1' => '/tmp/local/file'))
    $this->outputTempFiles = array();
    foreach ($this->shellCommand->getOutputs() as $key => $url)
    {
      $this->outputTempFiles[$key] = tempnam($this->_getTempPath(), 'output-');
    }
  }

  // Compose commands using inputs and outputs hashes; error on insufficient information
  private function runCommands()
  {
    $inputOutputReplacerF = $this->generateInputOutputReplacements();
    foreach ($this->shellCommand->getCommands() as $command)
    {
      // munge inputs & outputs
      $actualCommand = $inputOutputReplacerF($command);
      //$actualCommand = escapeshellcmd($actualCommand);
      exec($actualCommand, $output, $returnCode);
      if ($returnCode !== 0) throw new Exception("When running command '{$actualCommand}', encountered error: '" . var_export($output, true) . "', produced return code '{$returnCode}'.");
    }
  }

  private function processOutputs()
  {
    foreach ($this->shellCommand->getOutputs() as $key => $sendOutputToUrl)
    {
      $outputTempFile = $this->outputTempFiles[$key];
      $this->processOutput($outputTempFile, $sendOutputToUrl);
    }
  }

  private function cleanupTempFiles()
  {
    // Delete input & output files

    $allFiles = array_merge(array_values($this->inputTempFiles), array_values($this->outputTempFiles));
    foreach ($allFiles as $localFile)
    {
      if (file_exists($localFile)) unlink($localFile);
    }
  }

  /**
   * Return a function that will munge a ShellCommand "command" to use the temporary inputs and outputs
   *
   * @return function Function with prototype: (string) function($inputCommand)
   */
  private function generateInputOutputReplacements()
  {
    // generate replacements map
    $replacementMap = array();
    // Substitute inputs
    foreach ($this->inputTempFiles as $key => $localPath)
    {
      $replacementMap["%%inputs.{$key}%%"] = $localPath;
    }
    // Substitute outputs
    foreach ($this->outputTempFiles as $key => $localPath)
    {
      $replacementMap["%%outputs.{$key}%%"] = $localPath;
    }

    $searches = array_keys($replacementMap);
    $replacements = array_values($replacementMap);
    return function($subject) use ($searches, $replacements) {
      return str_replace($searches, $replacements, $subject);
    };
  }

  /**
   * Downloads the file at the given URL and returns the temp
   * location where the file is stored locally.
   *
   * @param string The URL of the file to download.
   * @return string The local temp file path where the downloaded file is stored.
   * @throws Exception If the HTTP code of the download response is non-2XX.
   */
  private function processInput($url)
  {
    // Generate local (tmp) path to store the downloaded file
    $extension        = pathinfo($url, PATHINFO_EXTENSION);
    $tmpFile          = tempnam($this->_getTempPath(), 'input-');
    $inputTmpFilePath = "{$tmpFile}.{$extension}";

    // Download the file
    $fileHandle = fopen($inputTmpFilePath, 'x');  // x prevents race conditions on the file existing...
    if ($fileHandle === false) throw new Exception("Unable to create/open {$inputTmpFilePath}.");

    $curlHandle = curl_init();
    curl_setopt($curlHandle, CURLOPT_FILE, $fileHandle);
    curl_setopt($curlHandle, CURLOPT_URL,  $url);
    $output = curl_exec($curlHandle);

    // Get the results
    $httpCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);

    // Close cURL
    curl_close($curlHandle);
    fclose($fileHandle);
    unlink($tmpFile); // We don't need the actual tmpfile; we were just using it as a mutex for the filename. We only need the one with the extension. 

    // Check the return code
    if ($httpCode >= 300)
    {
      $message = "Error downloading file from {$url} to {$inputTmpFilePath} due to error: " . var_export($output, true) . " and error code '{$httpCode}'.";
      throw new Exception($message);
    }

    return $inputTmpFilePath;
  }

  /**
   * Uploads the file at the given local file path to the target URL.
   *
   * Handles delivery of local output.
   * - s3://my.bucket/path/to/key.extension
   * - http://my.domain/path - HTTP POST
   * - capture://key - Include contents in notifications as $data['capture'][<captureKey>]
   *
   * @param string The local file path of the file to upload.
   * @param string The target URL where we should post the file.
   * @throws Exception If the HTTP code of the upload response is non-2XX.
   */
  private function processOutput($localFilePath, $targetUrl)
  {
    if ($targetUrl == '/dev/null') return NULL;

    if (!file_exists($localFilePath)) throw new Exception("Could not find file at path '{$localFilePath}' for upload.");

    $scheme = strtolower(parse_url($targetUrl, PHP_URL_SCHEME));
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
        throw new Exception("Invalid output scheme '{$scheme}'.");
    }
  }

  private function _uploadToS3($localFilePath, $targetUrl)
  {
    $creds = array('key' => $this->s3Key, 'secret' => $this->s3SecretKey);

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
    $cmd = "curl -T " . escapeshellarg($localFilePath) . " " . escapeshellarg($targetUrl);
    exec($cmd, $output, $retval);

    // Check the return code
    if ($retval !== 0)
    {
      $message = "Error uploading file from {$localFilePath} to {$targetUrl} due to error: " . var_export($output, true) . " and error code '{$retval}'.";
      $e = new Exception($message);
      throw $e;
    }
  }

  private function _writeToCaptureData($localFilePath, $targetUrl)
  {
    $captureKey = parse_url($targetUrl, PHP_URL_HOST);
    if (!$captureKey) throw new Exception("No capture key specified in {$targetUrl}");
    if (isset($this->capture[$captureKey])) throw new Exception("Capture key {$captureKey} specified twice.");

    $this->capture[$captureKey] = file_get_contents($localFilePath);
  }

  /**
   * Send status notifications for this job to each notification url.
   *
   * @param array The result data from the run.
   * @throws Exception If the HTTP code of the notification response is non-2XX.
   */
  private function sendNotifications($data)
  {
    if (count($this->shellCommand->getNotifications()) > 0 && !is_callable($this->notificationRunner)) throw new Exception("notificationRunner is not callable.");

    foreach ($this->shellCommand->getNotifications() as $url)
    {
      call_user_func($this->notificationRunner, $url, $data);
    }
  }

  private function _getTempPath()
  {
    $tmpDir = sys_get_temp_dir() . "/ShellCommandRunner";
    if (!is_dir($tmpDir))
    {
      mkdir($tmpDir, 0755, true);
    }
    return $tmpDir;
  }
}
