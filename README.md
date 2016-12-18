ShellCommand
============

A generic wrapper for execution of shell command to make it easy to horizontally scale such work.

Security Notice
===============
This code is not expected to accept arbitrary user input. If you create a ShellCommand without using escapeshellarg() and escapeshellcmd() then you are using this insecurely.

The reason we don't do this in ShellCommand is that escapeshellcmd() neuters pipes and redirection, and escapeshellarg() requires parsing which would introduce its own security risks.

Therefore we just punt on security and tell you to sanitize your inputs before creating a ShellCommand.

Changelog
=========
Read the changelog [here](CHANGELOG.md)
