Changelog
==========

AWS SDK 'shared nothing'
-----------------------
Presently, this project is heavily used in Tourbuzz Monolith and Tourbuzz Imageprocessor projects.
However, the former depends on the AWS SDK version 1.x, but the Imageprocessor uses 3.x.

In order to solve this dependency mismatch we have opted to get rid of AWS SDK from our composer.json.
The `ShellCommandRunner` class (which made use of the S3Client), now uses an `S3UploaderInterface` instance
for uploading to S3. We ship with two implementations: `S3UploaderForAwsSdkV1` and `S3UploaderForAwsSdkV3`.

If you create a new instance of ShellCommandRunner without specifying any S3Uploader implementation, we'll default
the uploader to `S3UploaderForAwsSdkV1`. You can override this by passing an instance of any class implementing
the `S3UploaderInterface` as the third argument of the `__construct()` or `create()` methods.
