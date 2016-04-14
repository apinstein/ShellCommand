Changelog
==========

v1.2.0
------
- HTTPS support for input & output urls.

Breaking changes:

- Upgraded to AWS SDK v3.17.5. The AWS S3 client now requires an aws region, you have to define 's3Region' property
in the ShellCommand's 'customData'. Note this is only required if your input or output urls relies on S3.
