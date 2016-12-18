<?php

use Aws\S3\S3Client;
use Aws\Common\Exception\MultipartUploadException;
use Aws\S3\Model\MultipartUpload\UploadBuilder;

class S3UploaderForAwsSdkV1 implements S3UploaderInterface
{
    private $s3Key;
    private $s3SecretKey;

    /**
     * {@inheritDoc}
     */
    public function setCredentials($s3Key, $s3SecretKey)
    {
        $this->s3Key       = $s3Key;
        $this->s3SecretKey = $s3SecretKey;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setRegion($region)
    {
        // Not required for AWS SDK version 1.
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function putObject($sourceFilePath, $bucket, $targetPath)
    {
        $creds = [
            'key'    => $this->s3Key,
            'secret' => $this->s3SecretKey
        ];

        $s3       = S3Client::factory($creds);
        $uploader = UploadBuilder::newInstance()
            ->setClient($s3)
            ->setSource($sourceFilePath)
            ->setBucket($bucket)
            ->setKey($targetPath)
            ->build()
        ;

        try {
            $uploader->upload();
        } catch (MultipartUploadException $e) {
            $uploader->abort();
            throw $e;
        }
    }
}
