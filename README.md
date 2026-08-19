# StraboSpot Backend Repository

This repository contains code necessary for the server-side functions of the StraboSpot project, including the strabospot.org web site, the database API, and the shapefile parser.


## NSF Funding ##

Development and operation of StraboSpot ([strabospot.org](https://strabospot.org/)) is supported by the U.S. National Science Foundation (NSF). We gratefully acknowledge the following NSF awards, as well as all prior NSF support of the StraboSpot project. See also the [NSF Funding page](https://strabospot.org/nsf_funding) at strabospot.org.

**Collaborative Research: Frameworks: Automated Quality Assurance and Quality Control for the StraboSpot Geologic Information System and Observational Data** (NSF CSSI)
* [2311819](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2311819) University of Kansas
* [2311820](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2311820) Temple University
* [2311821](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2311821) Oregon State University
* [2311822](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2311822) University of Wisconsin-Madison
* [2311823](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2311823) Texas A&M University

**Collaborative Research: GEO OSE Track 2: Developing CI-enabled collaborative workflows to integrate data for the SZ4D (Subduction Zones in Four Dimensions) community**
* [2324709](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2324709) University of Kansas
* [2324710](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2324710) University of Wisconsin-Madison
* [2324711](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2324711) Texas A&M University
* [2324712](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2324712) University of California-Santa Cruz
* [2324713](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2324713) University of Washington
* [2324714](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2324714) Northern Arizona University

**Collaborative Research: GEO OSE Track 2: Building a multiscale community-led ecosystem for crustal geology through the integration of Macrostrat and StraboSpot**
* [2324579](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2324579) University of Wisconsin-Madison
* [2324580](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2324580) University of Kansas

**Collaborative Research: Sustained Resources: Prototyping a Framework for FAIR Data Communities: The Tephra Information Portal (TIP)**
* [2411331](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2411331) Concord University
* [2411332](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2411332) University of Maine
* [2411333](https://www.nsf.gov/awardsearch/show-award/?AWD_ID=2411333) Columbia University

Any opinions, findings, and conclusions or recommendations expressed in this material are those of the authors and do not necessarily reflect the views of the National Science Foundation.



## Server Requirements: ##

* Apache web server
* PostgreSQL database server (for authentication and spatial functions)
* PostGIS (for spatial functions)
* Neo4j Database


## Apache Config: ##

The following should be included in the Apache config file:

CORS Headers:
~~~ bash
Header always set Access-Control-Allow-Origin "*"
Header always set Access-Control-Allow-Methods "POST, GET, OPTIONS, DELETE, PUT"
Header always set Access-Control-Max-Age "1000"
Header always set Access-Control-Allow-Headers "x-requested-with, Content-Type, origin, authorization, accept, client-security-token"
~~~

Postgres Authentication:
~~~ bash
        <Directory /var/www/db/>
                AuthName "Password Required."
                AuthType Basic
                AuthBasicAuthoritative Off
                Auth_PG_host localhost
                Auth_PG_port 5432
                Auth_PG_user readonly
                Auth_PG_pwd =postgrespasswordhere
                Auth_PG_database strabospot
                Auth_PG_pwd_table users
                Auth_PG_uid_field email
                Auth_PG_pwd_field password
                Auth_PG_encrypted on
                Auth_PG_pwd_whereclause " and active = TRUE "
                require valid-user
                Options Indexes FollowSymLinks MultiViews
                AllowOverride All
                Order allow,deny
                allow from all
        </Directory>
~~~


## PostgreSQL Config: ##

For user authentication, the following table needs to be created to store user information:
~~~ sql
CREATE TABLE users (
    pkey integer NOT NULL,
    firstname character varying(255) NOT NULL,
    lastname character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    password character varying(255) NOT NULL,
    hash character varying(255) NOT NULL,
    active boolean DEFAULT false NOT NULL
);

CREATE SEQUENCE users_pkey_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

ALTER TABLE ONLY users ALTER COLUMN pkey SET DEFAULT nextval('users_pkey_seq'::regclass);
~~~


## Config Files ##

All configuration variables are stored in includes/config.inc.php.
Mail functions require a gmail account.
~~~ php
<?php

/*
config.inc.php
Config Variables for connection to databases and email
*/

$neousername = "myneo4jusername"; 			//Neo4j username
$neopassword = "myneo4jpassword"; 			//Neo4j password
$neohost = "neo4jhostname"; 				//Neo4j host
$neoport = 7687; 							//Neo4j port
$neomode = "bolt"; 							//Neo4j connection mode
$dbusername = "mydbusername"; 				//Postgres username
$dbpassword = "mydbpassword"; 				//Postgres password
$dbname = "mydbname"; 						//Postgres database name
$dbhost = "mydbhost"; 						//Postgres database host
$straboemailaddress = "myemail"; 			//Gmail address
$straboemailpassword = "myemailpassword" 	//Gmail password
$mailchimpAPIkey = "mailchimpapikey"; 		//For maintaining mailchimp mailing list
$captchasecret="googlecaptchakey"; 			//Google captcha key
$jwtsecret = "jwtsigningkey"; 				//JWT signing key
$pushover_token = "pushovertoken"; 			//For alerting about new user registrations
$pushover_user = "pushoveruser"; 			//Pushover user token
$vloc="/var/www/versions"; 					//location to store versions

?>
~~~








