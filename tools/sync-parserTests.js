#!/usr/bin/env node

"use strict";

require('../core-upgrade.js');

/**
   == USAGE ==
   Script to synchronize parsoid parserTests with parserTests in other repos.

   Basic use:
     $PARSOID is the path to a checked out git copy of Parsoid
     $REPO is the path to a checked out git copy of the repo containing
       the parserTest file. (Check the `repo` key in tests/parserTests.json)
     $BRANCH is a branch name for the patch to $REPO (ie, 'ptsync-<date>')
     $TARGET identifies which set of parserTests we're synchronizing.
       (This should be one of the top-level keys in tests/parserTests.json)

   $ cd $PARSOID
   $ tools/sync-parserTests.js $REPO $BRANCH $TARGET
   $ cd $REPO
   $ git rebase master
     ... resolve conflicts, sigh ...
   $ php tests/parser/parserTests.php
     ... fix any failures by marking tests parsoid-only, etc ...
   $ git review

     ... time passes, eventually your patch is merged to $REPO ...

   $ cd $PARSOID
   $ tools/fetch-parserTests.txt.js $TARGET --force
   $ bin/parserTests.js --rewrite-blacklist
   $ git add -u
   $ git commit -m "Sync parserTests with core"
   $ git review

  Simple, right?
== USAGE ==
*/

var yargs = require('yargs');
var childProcess = require('child_process');
var async = require('async');
var path = require('path');
var fs = require('fs');

var testDir = path.join(__dirname, '../tests/');
var testFilesPath = path.join(testDir, 'parserTests.json');
var testFiles = require(testFilesPath);

var DEFAULT_TARGET = 'parserTests.txt';

var strip = function(s) {
	return s.replace(/(^\s+)|(\s+$)/g, '');
};

(function() {
	// Option parsing and helpful messages.
	var usage = 'Usage: $0 <repo path> <branch name> <target>';
	var opts = yargs.usage(usage, {
		'help': { description: 'Show this message' },
	});
	var argv = opts.argv;
	if (argv.help || argv._.length < 2 || argv._.length > 3) {
		opts.showHelp();
		var morehelp = fs.readFileSync(__filename, 'utf8');
		morehelp = strip(morehelp.split(/== USAGE ==/, 2)[1]);
		console.log(morehelp.replace(/^ {3}/mg, ''));
		return;
	}

	// Ok, let's do this thing!
	var mwpath = path.resolve(argv._[0]);
	var branch = argv._[1];
	var targetName = argv._[2] || DEFAULT_TARGET;

	if (!testFiles.hasOwnProperty(targetName)) {
		console.warn(targetName + ' not defined in parserTests.json');
		return;
	}

	var file = testFiles[targetName];
	var oldhash = file.latestCommit;

	var mwexec = function(cmd) {
		return function(callback) {
			console.log('>>>', cmd.join(' '));
			childProcess.spawn(cmd[0], cmd.slice(1), {
				cwd: mwpath,
				env: process.env,
				stdio: 'inherit',
			}).on('close', function(code) {
				callback(code === 0 ? null : code, code);
			});
		};
	};

	var q = [];
	var pPARSERTESTS = path.join(__dirname, '..', 'tests', targetName);
	var mwPARSERTESTS = path.join(mwpath, file.path);

	// Fetch current Parsoid git hash.
	var phash;
	q.push(function(callback) {
		childProcess.execFile('git', ['log', '--max-count=1', '--pretty=format:%H'], {
			cwd: __dirname,
			env: process.env,
		}, function(error, stdout, stderr) {
			if (error) { return callback(error.code || 1); }
			phash = strip(stdout);
			callback(null, 0);
		});
	});
	q.push(function(callback) {
		// A bit of user-friendly logging.
		console.log('Parsoid git HEAD is', phash);
		console.log('>>> cd', mwpath);
		callback(null);
	});

	// Create a new mediawiki/core branch, based on the previous sync point.
	q.push(mwexec('git fetch origin'.split(' ')));
	q.push(mwexec(['git', 'checkout', '-b', branch, oldhash]));
	var cleanup = function(callback) {
		var qq = [
			mwexec('git checkout master'.split(' ')),
			mwexec(['git', 'branch', '-d', branch]),
		];
		async.series(qq, callback);
	};

	// Copy our locally-modified parser tests over to mediawiki/core.
	q.push(function(callback) {
		// cp __dirname/parserTests.txt $mwpath/tests/parser
		fs.readFile(pPARSERTESTS, function(err, data) {
			if (err) { return cleanup(function() { callback(err); }); }
			console.log('>>>', 'cp', pPARSERTESTS, mwPARSERTESTS);
			fs.writeFile(mwPARSERTESTS, data, function(err2) {
				if (err2) { return cleanup(function() { callback(err2); }); }
				callback();
			});
		});
	});

	// Make a new mediawiki/core commit with an appropriate message.
	q.push(function(callback) {
		var commitmsg = 'Sync up with Parsoid ' + targetName;
		commitmsg += '\n\nThis now aligns with Parsoid commit ' + phash;
		mwexec(['git', 'commit', '-m', commitmsg, mwPARSERTESTS])(callback);
	});

	// Ok, run these commands in series, stopping if any fail.
	async.series(q, function(err, allresults) {
		if (err) { process.exit(err); }

		// ok, we were successful at making the commit.  Give further instructions.
		console.log();
		console.log('Success!  Now:');
		console.log(' cd', mwpath);
		console.log(' git rebase origin/master');
		console.log(' .. fix any conflicts .. ');
		console.log(' php tests/parser/parserTests.php');
		console.log(' git review');

		// XXX to rebase semi-automatically, we might do something like:
		//  mwexec('git rebase origin/master'.split(' '))(function(err, code) {
		//  });
		// XXX but it seems rather confusing to do it this way, since the
		// current working directory when we finish is still parsoid.

		process.exit(0);
	});
}());
