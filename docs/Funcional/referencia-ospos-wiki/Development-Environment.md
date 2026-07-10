
1. Read the [Requirements section in Development Index](Development-Index#server-requirements).
2. Read the [Architecture section](Development-Index#architecture) first.
3. After reading this document, read the [Error Logging](Error-Logging) guide.

For the impatient: read the [Development Index](Development-Index) wiki page.

## Setup using docker

Docker and docker-compose are recommended to make a first full build. If you don't want to use docker, read the Basic tool setup section further on here.

```
docker run --rm -v $(pwd):/app jekkos/composer composer install
docker run --rm -v $(pwd):/app -w /app jekkos/composer php bin/install.php translations develop
npm install
docker-compose -f docker-compose.dev.yml build
docker-compose -f docker-compose.dev.yml up
```

## Workflow

Obviously as github does, by pull request, there's no extended process of "reviews", the pull are accepted once any developer revised and confirm works, but try to adhere to coding standard and documentation as described in this document.

Please read the [development index code tips and help](Development-Index#development-code-tips-and-help) wiki page section for more detailed information on.

## Code style

The application tries to adhere to [CodeIgniter 4 coding style](https://codeigniter4.github.io/userguide4/concepts/structure.html), must read carefully.

**IMPORTANT**: as discussed in #389, but due the application was a migration from CI3 some portions of the code might not comply yet. Please do not make pull requests only to format old code, do this only if new features are involved.

Always check the [Development code tips and help](Development-Index#development-code-tips-and-help) for how to code the controllers and views respect the models.

## Code documentation

The application generates code documentation using PHPDoc. For more details see [issue 1278](https://github.com/jekkos/opensourcepos/issues/1278).
Code documentation can be read using IDE tools that support PHPDoc.
See the [CodeIgniter 4 user guide](https://codeigniter4.github.io/userguide4/) for framework documentation.

## Basic Tool installation

The build tools can be installed permanently if one prefers not to use docker. Node.js, npm and composer are used and need to be installed:

    sudo apt-get install nodejs
    sudo apt-get install npm

Also composer needs to be installed, by example Debian derived distribution can see tutorial: https://getcomposer.org/download/

Once the basic tools are installed run `npm install` which will install all npm dependencies.

## Running js minification builds

The project uses gulp and npm to minify the final included javascript.
As first step you need to install npm once done you should issue the following command:

    npm install

This will install all required npm dependencies for building the project.

## Running CSS minification builds

The gulp build process will minify CSS and JavaScript files as needed. Run:

    npm run build

## Proper way to see js minifications changes

JS and CSS are cached, you just need to reload your page keeping the shift button pressed in your web browser.

This procedure are also recommended if you perform an update.

## Dotfiles for easy environment setup

The addition of the dotenv composer library makes it easy to configure different staging environments with database credentials and environment bound variables. Copy the `.env.example` file in the project root to `.env` and fill in variable values as needed. PHP display_errors will be set to 1 in development mode which will ease troubleshooting errors and debugging when things go awry.

## Minification setup using Docker

A full development environment can be easily configured using docker. For this purpose there is the `Dockerfile.test` file. You will need to mount the project directory path on the host in the docker container. First build and tag the docker image described in the `Dockerfile.test` file.

`docker build -f Dockerfile.test -t opensourcepos:test .`

As a final step you can run npm from the newly created image. Open up a terminal in the ospos directory and then issue following command

`docker run -v $(pwd):/data -f Dockerfile.test opensourcepos:test npm install`

Now at this point you can follow the [Development code tips and help](Development-Index#development-code-tips-and-help) to start making new features to the opensourcepos.

## Debugging PHP using XDebug and Docker

The PHP side of this application can also be debugged using a prebuilt Docker container. This container will add the XDebug PHP module that can be used from within IntelliJ or Eclipse. First check the ip address on your host's docker interface as it needs to be configured in the `docker-compose.dev.yml` file. Next you can use docker-compose to start the application with xdebug enabled by entering following command from the CLI (head for ospos directory first)

`docker-compose -f docker-compose.dev.yml up`

After the container is launched the xdebug connection should be available on port 9000 of the docker ip address. Add [this to the configuration in IntelliJ or Eclipse](https://gist.github.com/chadrien/c90927ec2d160ffea9c4) and you should be good to go.

# See also: 

* [Development index](Development-Index#tech-architecture)
* [Development code tips and help](Development-Index#development-code-tips-and-help)
* [Error Logging](Error-Logging)