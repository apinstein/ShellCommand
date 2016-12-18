<?php

/**
 * Helper for uploading assets to S3.
 * For usage, see ShellCommandRunner::_uploadToS3() method.
 */
interface S3UploaderInterface
{
    /**
     * Set the AWS SDK credentials.
     * @return $this
     */
    public function setCredentials($s3Key, $s3SecretKey);

    /**
     * Set the AWS region to where we want to upload the assets.
     * @return $this
     */
    public function setRegion($region);

    /**
     * Uploads an object to an S3 bucket.
     * @param string $sourceFilePath. The fully qualified path to the source file.
     * @param $bucket. The name of the destination S3 bucket.
     * @param $targetPath. The path where the file will live in the bucket.
     */
    public function putObject($sourceFilePath, $bucket, $targetPath);
}
