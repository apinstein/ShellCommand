<?php

use Aws\S3\S3Client;
use Aws\Common\Exception\MultipartUploadException;
use Aws\S3\Model\MultipartUpload\UploadBuilder;

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

  // URL re-writers allow the runnner to re-map URL schemes
  // For instance, a local runner may want to re-write s3:// urls as file:// urls for testing/offline development
  protected $inputUrlRewriter;
  protected $outputUrlRewriter;

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
   *                      error: [error message]
   *                    capture: [captured data hash]
   *                     custom: [custom data hash]
   *              - s3Key
   *              - s3SecretKey
   *              - inputUrlRewriter
   *              - outputUrlRewriter
   */
  public function __construct(ShellCommand $shellCommand, $options = array())
  {
    $this->shellCommand       = $shellCommand;
    $this->notificationRunner = isset($options['notificationRunner']) ? $options['notificationRunner'] : NULL;
    $this->s3Key              = isset($options['s3Key']) ? $options['s3Key'] : NULL;
    $this->s3SecretKey        = isset($options['s3SecretKey']) ? $options['s3SecretKey'] : NULL;
    $this->inputUrlRewriter   = isset($options['inputUrlRewriter']) ? $options['inputUrlRewriter'] : NULL;
    $this->outputUrlRewriter  = isset($options['outputUrlRewriter']) ? $options['outputUrlRewriter'] : NULL;
  }

  public static function create(ShellCommand $sc, $options = array())
  {
    return new ShellCommandRunner($sc, $options);
  }

  public function run()
  {
    $status = self::STATUS_FAILURE;
    $exception = NULL;
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
      $exception = $e;
      $error = $e->getMessage();
    }

    // finally
    $this->cleanupTempFiles();

    $resultData = array(
      'status'     => $status,
      'error'      => ($status === self::STATUS_FAILURE ? $error : NULL),
      'exception'  => ($status === self::STATUS_FAILURE ? $exception : NULL),
      'capture'    => $this->capture,
      'customData' => $this->shellCommand->getCustomData()
    );

    $this->sendNotifications($resultData);

    return $resultData;
  }

  /**
   * A default implementation of a notifier that hits a webhook.
   * Not robust (no retries or granular failure parsing) but helpful
   * during development.
   */
  public function webhookNotifier($url, $data) {
    $curlHandle = curl_init();
    curl_setopt($curlHandle, CURLOPT_URL,            $url);
    curl_setopt($curlHandle, CURLOPT_POST,           1);
    curl_setopt($curlHandle, CURLOPT_POSTFIELDS,     json_encode($data));
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
      $ext = pathinfo($url, PATHINFO_EXTENSION);
      $outputTmpPath = $this->generateTempfile('output-', $ext);
      $this->outputTempFiles[$key] = $outputTmpPath;
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
      if ($returnCode !== 0) throw new Exception("Command '{$actualCommand}' exited with status {$returnCode}: " . join("\n", $output));
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
  public function processInput($url)
  {
    if (is_callable($this->inputUrlRewriter))
    {
        $url = call_user_func($this->inputUrlRewriter, $url);
    }

    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME));

    // Generate local (tmp) path to store the downloaded file
    $extension        = pathinfo($url, PATHINFO_EXTENSION);
    $inputTmpFilePath = $this->generateTempfile('input-', $extension);

    switch ($scheme)
    {
      case 'http':
      case 'https':
        $this->_downloadHTTP($url, $inputTmpFilePath);
        break;
      case '':
      case 'file':
        $targetFile = parse_url($url, PHP_URL_PATH);
        $ok = copy($targetFile, $inputTmpFilePath);
        if ($ok === false) throw new Exception("copy({$targetFile}, {$inputTmpFilePath}) failed.");
        break;
      default:
        throw new Exception("Invalid input scheme '{$scheme}'.");
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
    if (!file_exists($localFilePath)) throw new Exception("Could not find file at path '{$localFilePath}' for upload.");

    if (is_callable($this->outputUrlRewriter))
    {
        $targetUrl = call_user_func($this->outputUrlRewriter, $targetUrl);
    }

    if ($targetUrl == '/dev/null') return NULL;

    $scheme = strtolower(parse_url($targetUrl, PHP_URL_SCHEME));
    switch ($scheme)
    {
      case 's3':
        $this->_uploadToS3($localFilePath, $targetUrl);
        break;
      case 'http':
      case 'https':
        $this->_uploadHTTP($localFilePath, $targetUrl);
        break;
      case 'capture':
        $this->_writeToCaptureData($localFilePath, $targetUrl);
        break;
      case 'file':
        $this->_writeLocally($localFilePath, $targetUrl);
        break;
      default:
        throw new Exception("Invalid output scheme '{$scheme}'.");
    }
  }

  private function _writeLocally($localFilePath, $targetFilePath)
  {
      $targetFile = parse_url($targetFilePath, PHP_URL_PATH);
      $targetDir = dirname($targetFile);
      if (!is_dir($targetDir))
      {
          $ok = mkdir($targetDir, 0777, true);
          if (!$ok) throw new Exception("mkdir({$targetDir}) trying to create an enclosing directory for target file.");
      }
      $ok = rename($localFilePath, $targetFile);
      if (!$ok) throw new Exception("rename({$localFilePath}, {$targetFile}) failed.");
  }

  private function _uploadToS3($localFilePath, $targetUrl)
  {
    $creds = array('key' => $this->s3Key, 'secret' => $this->s3SecretKey);

    // Gather info
    $urlParts = parse_url($targetUrl);
    if (!isset($urlParts['host'])) throw new Exception("No host could be parsed from {$targetUrl}.");
    if (!isset($urlParts['path'])) throw new Exception("No path could be parsed from {$targetUrl}.");

    $bucket   = $urlParts['host'];
    $path     = preg_replace('/^\//', '', $urlParts['path']);

    // Upload!
    $s3 = S3Client::factory($creds);
    $uploader = UploadBuilder::newInstance()
      ->setClient($s3)
      ->setSource($localFilePath)
      ->setBucket($bucket)
      ->setKey($path)
      ->build()
      ;

    try {
      $uploader->upload();
    } catch (MultipartUploadException $e) {
      $uploader->abort();
      throw $e;
    }
  }

  private function _downloadHTTP($sourceUrl, $localFilePath)
  {
    // Download the file
    $fileHandle = fopen($localFilePath, 'w');
    if ($fileHandle === false) throw new Exception("Unable to create/open {$localFilePath}.");

    $curlHandle = curl_init();
    curl_setopt($curlHandle, CURLOPT_FILE, $fileHandle);
    curl_setopt($curlHandle, CURLOPT_URL,  $sourceUrl);
    $output = curl_exec($curlHandle);

    if ($output === false)
    {
      curl_close($curlHandle);
      fclose($fileHandle);
      throw new Exception("Curl exec fail {$sourceUrl}");
    }

    // Get the results
    $httpCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);

    // Close cURL
    curl_close($curlHandle);
    fclose($fileHandle);

    // Check the return code
    if ($httpCode >= 300)
    {
      $message = "Error downloading file from {$sourceUrl} to {$localFilePath} due to error: " . var_export($output, true) . " and error code '{$httpCode}'.";
      throw new Exception($message);
    }

  }

  private function _uploadHTTP($localFilePath, $targetUrl)
  {
    $fp = fopen($localFilePath, 'r');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $targetUrl);
    curl_setopt($ch, CURLOPT_UPLOAD,         1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_INFILE,         $fp);
    curl_setopt($ch, CURLOPT_INFILESIZE,     filesize($localFilePath));
    $body       = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_errno($ch);
    curl_close($ch);

    fclose($fp);

    if ($curlError) {
      throw new Exception("curl error uploading {$localFilePath} to {$targetUrl}: {$curlError}");
    }
    else if ($httpStatus !== 200)
    {
      throw new Exception("Error uploading {$localFilePath} to {$targetUrl}: server responded with {$httpStatus}: {$body}");
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

  /**
   * Generates a tempfile with an optional extension; useful for scripts that rely on output filenames for magic (ie ImageMagick format conversion)
   *
   * @param string A prefix for the temp file
   * @param string The extension for the file (with or without the .)
   * @return string The full filesystem path to the temp file.
   * @throws object Exception If the temp file cannot be created.
   */
  public function generateTempfile($prefix, $ext = NULL)
  {
    $tmpFileWithoutExtension = tempnam($this->_getTempPath(), $prefix);  // race-condition safe way to create uniquely named file; used as a mutex for the one w/extension

    if ($ext)
    {
      $ext = ltrim($ext, '.');
      $extensionWithDot = $ext ? ".{$ext}" : NULL;
      $tmpFilePath = "{$tmpFileWithoutExtension}{$extensionWithDot}";
      $fileHandle = fopen($tmpFilePath, 'x');  // x prevents race conditions on the file existing...
      unlink($tmpFileWithoutExtension);       // we don't need the original tempfile, once we get here we've safely got our own tempfile+extension
      if ($fileHandle === false) throw new Exception("Unable to create tempfile: {$tmpFilePath}");
      fclose($fileHandle);
    }
    else
    {
      $tmpFilePath = $tmpFileWithoutExtension;
    }
    return $tmpFilePath;
  }
}
