<?php

use Aws\S3\S3Client;

class S3UploaderForAwsSdkV3 implements S3UploaderInterface
{
    // found in vendor/aws/aws-sdk-php/src/data/s3 dir.
    const S3_CLIENT_VERSION = '2006-03-01';

    private $s3Key;
    private $s3SecretKey;
    private $region;

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
        $this->region = $region;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function putObject($sourceFilePath, $bucket, $targetPath)
    {
        // Upload!
        $options = [
            'region'  => $this->region,
            'version' => self::S3_CLIENT_VERSION,
        ];

        // Reference the credentials only* if they are passed down. If  the keys are not defined,
        // then the $options['credentials'] should be undefined too, which will force the SDK to look for the
        // credentials in the ENV variables.
        if ($this->s3Key && $this->s3SecretKey)
        {
            $options['credentials'] = [
                'key'    => $this->s3Key,
                'secret' => $this->s3SecretKey,
            ];
        }

        S3Client::factory($options)->putObject([
            'Bucket'     => $bucket,
            'Key'        => $targetPath,
            'SourceFile' => $sourceFilePath,
        ]);
    }
}
