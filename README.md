# codigniter_databaseDiffCheck[![Repo Size](https://reposs.herokuapp.com/?path=pjkaufman/codigniter_databaseDiffCheck)](https://github.com/pjkaufman/codigniter_databaseDiffCheck)  [![Current Issues](https://img.shields.io/github/issues/pjkaufman/codigniter_databaseDiffCheck.svg)](https://github.com/pjkaufman/codigniter_databaseDiffCheck/issues)  [![License](https://img.shields.io/github/license/pjkaufman/codigniter_databaseDiffCheck.svg)](https://github.com/pjkaufman/codigniter_databaseDiffCheck/blob/master/LICENSE)
###### originally by: Gordon Murray
Compares 2 schemas and responds with the sql to make the schemas the same.
## Requirements:
Must have two database connections in the databases.php file in the application's config folder or just two databases that are accessible.
## Setup
###### Codeigniter Controllers
-Put in the controllers folder of your application folder  
-The call the controller using your base path /compare
###### PHP Classes
-Copy the class into the desired file or include it using ``include 'filename';``  
-Create an instance of the class in the desired file and pass in your hostname, username, password, and database name in an associative array.  
#### TODO
-Add a version that uses a snapshot of a database and one database connection    
