## CS-HitsTracker


1.) LICENSE: see the LICENSE file.
 
2.) BASIS
	
This system is built to log hits to a database whenever an image is displayed.  Accounts can be setup which then allows images linked to that account to display special information based on arguments passed to the script.
	
3.) REQUIREMENTS:

This system requires several other libraries to perform it's core functions:
 * cs-content         https://github.com/crazedsanity/cs-content
 * cs-arraytopath     https://github.com/crazedsanity/cs-arraytopath
 * cs-phpxml          https://github.com/crazedsanity/cs-phpxml

4.) PERFORMANCE

CS-HitsTracker was built to run on slow or overburdened servers.  It is assumed that connections to the database, along with modifications and the insertion/manipulation of data, will be a very slow and cumbersome process compared to serving up just images.  This is automatically handle in the following way:

 a.) Logging hits quickly:
 * Hits are logged by creating new text files with hit information
 * shell script regularly parses, inserts, and deletes these files regularly parse and insert into the database.
 b.) Image files
 * stored as files on the server (not in DB)
 * default image is always assumed to be available
 * if special images are missing/unreadable, the default image is used.
 * no special generation/modification happens on-the-fly (files are static).
 c.) Special privilege/settings handling
 * special privileges are stored in an account-specific file on server
 * files must be fast to parse and understand
 * files are created/updated/deleted by the admin system

6.) CLIENT USAGE:
	
All a client has to do in order to use your installation of CS-HitsTracker for the purpose of logging hits is to put the image on their site. Example:
	
		<img src="http://your.site.com/php-bin/hitsTracker.php?acctName=axelFoley">
	
The "acctName" is optional, and simply helps to associate the hit with a particular account name; if not provided, the account name is derived from the domain, if applicable.
