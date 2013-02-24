<?php
// vim: set expandtab tabstop=4 shiftwidth=4 syntax=php:

$spec = Pearfarm_PackageSpec::create(array(Pearfarm_PackageSpec::OPT_BASEDIR => dirname(__FILE__)))
             ->setName('ShellCommand')
             ->setChannel('apinstein.pearfarm.org')
             ->setSummary('A generic wrapper for execution of shell command to make it easy to horizontally scale such work.')
             ->setDescription('Easily allow your applications to enqueue jobs and run workers to process jobs. Supports multiple queue stores, priorities, locking, etc.')
             ->setReleaseVersion('1.0.1')
             ->setReleaseStability('stable')
             ->setApiVersion('1.0')
             ->setApiStability('stable')
             ->setLicense(Pearfarm_PackageSpec::LICENSE_MIT)
             ->setNotes('Initial release.')
             ->addMaintainer('lead', 'Alan Pinstein', 'apinstein', 'apinstein@mac.com')
             ->addGitFiles()
             ->addExcludeFiles(array('.gitignore', 'pearfarm.spec', 'composer.json'))
             ->addExcludeFilesRegex('/^vendor/')
             ;
