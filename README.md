## HAL 9000 Console Application

The HAL 9000 Console Application can perform various build and deploy operations and replaces the previous
monolithic pusher script. The build and deploy processes are now separate - in order to deploy an application, it
must first be built for the correct environment, a build ID obtained, and then that build ID can be deployed to
one or more servers or retrieved through the API for use elsewhere.

Under normal operations, a user will interact with the build system through the HAL 9000 web application - this
application will then be called in the background.

Table of Contents:
* [Usage](#usage)
* [Available Commands](#available-commands)
* [Worker Commands](#worker-commands)
* [Application scripting environment](#application-scripting-environment)
    * [.hal9000.yml](#hal9000yml)
    * [On Build](#on-build)
    * [On Push](#on-push)
* [Deployment](#deployment)
* [Configuration](#configuration)
* [Testing](#testing)

## Usage

The application can be run as follows:
```bash
bin/hal [command]
```

Help for any command may be viewed by adding the --help flag as follows.
```bash
bin/hal [command] [options] --help
```

Debug messaging will be displayed if run with verbosity.
```bash
bin/hal [command] -v
```

Contextual variables will be displayed if run with increased verbosity.
```bash
bin/hal [command] -vv
```

## Available Commands

Command          | Description
---------------- | -----------
`build:create`   | Create a build job for an application based on an environment
`build:build`    | Download, build, and archive a build
`build:remove`   | Remove archive for a build.
`push:create`    | Create a push job for an application based on a build and deployment
`push:push`      | Push a built application to a server
`builds:list`    | List all existing builds.

## A note on `builds:list` and `build:remove`

The build removal command can take multiple build IDs as arguments to remove multiple builds at once.
`builds:list` can be used to generate porcelain output that can be consumed by `build:remove` using xargs.

The default output of `builds:list` is a table that shows the full path of the build archive.
```bash
./hal builds:list
```

Note that output is limited to 500 results, and paged. Specify further pages with the `--pages` flag.
```bash
./hal builds:list --page=2
```

Results can be filtered by build status, repository ID, environment name, and age. See the help documentation for more information.
```bash
./hal builds:list --status=Success --environment=test --repository=5 --older-than=2014-05-01

// help documentation
./hal help builds:list
```

The `--verify` flag will check that the archive file actually exists where we think it should. It will only verify successful builds.
```bash
./hal help builds:list --verify
```

Generate porcelain output (newline delimited build IDs) to pipe to build removal.
The `--spaces` flag specifies space as the delimiter instead of newline, for easier xargs usability.
```bash
./hal builds:list --status=Success --environment=test --porcelain --spaces | xargs ./hal build:remove
```

## Worker Commands

These commands can be set on a timer or cron to pick up and process waiting actions.

Command          | Description
---------------- | -----------
`worker:build`   | Find and build all waiting builds.
`worker:push`    | Find and push all waiting pushes.

**A note on permissions:**  
Within the console application, no user switching or `sudo` is performed. The worker commands must be run as the
user with the proper permissions to perform the required system actions.

### Convenience bins
Two convenience bash scripts are included to make it easier to set up cronjobs every 15 seconds, rather than every minute which cron supports.

If no arguments are provided, both scripts will fire immediately, then wait 30 seconds and fire again (0s and 30s marks).
If `--alt` is used as a parameter, the scripts will wait 15 seconds, fire, then wait 30 seconds and fire again (15s and 45s marks).

Command            | Description
------------------ | -----------
`bin/worker-build` | Bash script for builds
`bin/worker-push`  | Bash script for pushes

## Application scripting environment

### .hal9000.yml

A `.hal9000.yml` yaml file can be placed into the project repository to enable commands and other options for projects.

```yaml
# Environment to use to build application.
# Optional. The default is "global"
system: ''

# Directory of build dist to archive, relative to application root.
# Optional. The default is the application root.
dist: ''

# Files or directories to exclude from push
# Optional. The default is the "config/database.ini" and "data/".
exclude: ''

# Command to install dependencies, compile application
# Can be a single command, or list of commands.
build: ''

# Command to transform build before push
# Can be a single command, or list of commands.
build_transform: ''

# Command to run on target server, before push
# Can be a single command, or list of commands.
pre_push: ''

# Command to run on target server, after push
# Can be a single command, or list of commands.
post_push: ''
```

Example:

```yaml
build:
    - 'bin/composer install --no-dev --no-interaction --ansi --optimize-autoloader'
    - 'npm install --production --color=always'
    - 'bundle install --frozen'
    - 'bin/compile'

post_push: 'bin/set-permissions'
exclude:
    - 'config/database.ini'
    - 'data/'
```

Please note, currently the only system or container supported is "global". In addition, the total commands in each command list for each step must be less than 10.

### On Build

During the build process, The following environment variables are available to application build scripts:

Variable         | Description
---------------- | -----------
HAL_BUILDID      | ID of the build
HAL_COMMIT       | 40 character commit SHA
HAL_GITREF       | Git reference (such as `master`)
HAL_ENVIRONMENT  | Environment (such as `test`, `beta`, `prod`)
HAL_REPO         | Hal name for the deployed application

### On Push

A yaml file in the following format is written to the application directory during a push:

```yaml
# filename: APPLICATION_ROOT/.hal9000.push.yml

id: ''         # Build ID
source: ''     # Full url of github repository
env: ''        # Environment of the build
user: ''       # Username of user that triggered the push
reference: ''  # Git Reference
commit: ''     # Git commit SHA
date: ''       # ISO 8601 date
```

The following environment variables are available to application pre and post push scripts:

Variable         | Description
---------------- | -----------
HAL_HOSTNAME     | Hostname of server
HAL_PATH         | Full path of application on server
HAL_BUILDID      | ID of the build
HAL_COMMIT       | 40 character commit SHA
HAL_GITREF       | Git reference (such as `master`)
HAL_ENVIRONMENT  | Environment (such as `test`, `beta`, `prod`)
HAL_REPO         | Hal name for the deployed application

## Deployment

`bin/deploy` must be run when deploying to an environment, as this copies environment specific settings to `config.env.yml`.
For development deployments, create a `config.env.yml` using `config.env.yml.dist` as a prototype.

### Configuration

Key                       | Description
------------------------- | -----------
email.notify              | A list of secondary email addresses to notify
environment.archive       | Path to permanent archive directory for successful builds
environment.temp          | Path to temporary build directory
environment.path          | System PATH
environment.home          | System HOME
push.sshUser              | Username used to ssh to servers for syncing code
github.token              | Github Enterprise authentication token
github.com.token          | Github.com authentication token
github.baseurl            | Github API url
github.baseurl.site       | Github url
hal.baseurl               | HAL 9000 Application url

## Testing

The porcelain commands can be used to create and build entities in a single process:

Build example:
```
bin/hal build:build $(bin/hal build:create REPOSITORY_ID ENVIRONMENT_ID GIT_REFERENCE --porcelain)
bin/hal b:b $(bin/hal b:c REPOSITORY_ID ENVIRONMENT_ID master --porcelain)
```

Push example:
```
bin/hal push:push $(bin/hal push:create BUILD_ID DEPLOYMENT_ID --porcelain)
bin/hal p:p $(bin/hal p:c BUILD_ID DEPLOYMENT_ID --porcelain)
```
