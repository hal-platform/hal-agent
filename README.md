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
* [Deployment](#deployment)
    * [Unix Build Server Preparation](#unix-build-server-preparation)
    * [Windows Build Server Preparation](#windows-build-server-preparation)
* [Application scripting environment](#application-scripting-environment)
    * [.hal9000.yml](#hal9000yml)
    * [On Build](#on-build)
    * [On Push](#on-push)

## Usage

The application can be run as follows:
```bash
bin/hal [command]
```

Help for any command may be viewed by adding the --help flag as follows.
```bash
bin/hal [command] [options] --help
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
`docker:refresh` | Refresh dockerfile sources on build server

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

### Convenience bins
Two convenience bash scripts are included to make it easier to set up cronjobs every 15 seconds, rather than every minute which cron supports.

If no arguments are provided, both scripts will fire immediately, then wait 30 seconds and fire again (0s and 30s marks).
If `--alt` is used as a parameter, the scripts will wait 15 seconds, fire, then wait 30 seconds and fire again (15s and 45s marks).

Command            | Description
------------------ | -----------
`bin/worker-build` | Bash script for builds
`bin/worker-push`  | Bash script for pushes

## Deployment

`bin/deploy` must be run when deploying to an environment, as this copies environment specific settings to `config.env.yml`.
For development deployments, create a `config.env.yml` using `environment/dev.yml` as a prototype.

Unix builds require a docker-supported build server. `boot2docker` can be used for this purpose.

### Unix Build Server Preparation

- `$user` is the dedicated user that runs the agent (Example: `hal9000test`).
- `$syncer` is the user code is rsynced through (Example: `codexfer`).

1. Agent server setup
    * `/var/hal9000agent` must exist and be owned by `$syncer:hal-agent`.
    * Deploy agent to **agent server**.
    * **$user** must be able to ssh (passwordless) to the dedicated **build server**.
    * **$user** must be able to ssh (passwordless) as $syncer to all deploy web/app servers.
    * `/tmp/hal9000` must exist and be owned by **$user**.
    * `/builds/hal9000` must exist and be owned by **$user**. (`/builds/hal9000test` for test environment)
2. Build server setup
    * Docker must be installed (>=1.5).
    * **$user** must be able to sudo docker.
    * `/var/hal9000` must exist and be owned by **$user**.
    * `/docker-images` must exist and be owned by **$user**.
3. Deploy **docker images** to build server
    * The agent command "docker:refresh" will automatically do this (As long as the directory is present!).
4. It should work

### Windows Build Server Preparation

1. Enable SSH and SCP on windows agent
    - Cygwin, CopSSH, etc
2. Install **Windows 8 & .NET Framwork SDK**
    - Ensure `MsBuild.exe` is installed for the following versions:
        - `2.0.50727`
        - `3.5`
        - `4.0.30319`
3. Install **Microsoft Visual Studio 2010 Shell Redistributable Package**
4. Install **Microsoft Visual Studio 2013 Shell Redistributable Package**
5. Install **nuget** to `C:\Program Files (x86)\Nuget`.
7. Create `C:\builds` directory.
6. Update path in `.bashrc` for build user:
   ```
   export PATH="$PATH:$PROGRAMFILES/Nuget"
   export PATH="$PATH:$PROGRAMFILES/IIS/Microsoft Web Deploy V3"
   export PATH="$PATH:$WINDIR/System32/WindowsPowerShell/v1.0"
   ```

## Application scripting environment

### Build systems and deployment types

This agent supports the following:

Build Systems:
- `unix` (Remotely run through docker with default docker image)
- `docker:$image` (Remotely run through docker with custom docker image`$image`)
- `windows` (Remotely run)

Deployment Types:
- Rsync
- EC2 (Autoscaling, rsync)
- Elastic Beanstalk

**Please note:** 
For EC2 and EB deployments "server commands" are skipped (Both pre-push and post-push).

### .hal9000.yml

A `.hal9000.yml` yaml file can be placed into the project repository to enable commands and other options for projects.

```yaml
# Environment to use to build application.
# Optional. The default is "unix".
# Also available: "docker:$dockerimage" to specify a custom docker image as build system.
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

Please note, the total commands in each command list for each step must be less than 10.

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

The following environment variables are available to application **build_transform**, **pre_push** and **post_push** scripts:

Variable         | Description
---------------- | -----------
HAL_HOSTNAME     | Hostname of server
HAL_PATH         | Full path of application on server
HAL_BUILDID      | ID of the build
HAL_COMMIT       | 40 character commit SHA
HAL_GITREF       | Git reference (such as `master`)
HAL_ENVIRONMENT  | Environment (such as `test`, `beta`, `prod`)
HAL_REPO         | Hal project name for the deployed application
