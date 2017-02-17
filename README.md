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

Command                       | Description
----------------------------- | -----------
`management:build:create`     | Create a build job for an application and environment.
`management:release:create`   | Create a release job for a build and target.
`management:build:start`      | Create and run a build.
`management:release:start`    | Create and deploy a release.
`runner:deploy`               | Deploy a release.
`runner:build`                | Run a build.
`docker:refresh`              | Refresh dockerfile sources on build server
`docker:status`               | Get docker status and filesystem useage
`server:connections`          | Validate agent can talk to servers

## Worker Commands

These commands can be set on a timer or cron to pick up and process pending jobs.

Command          | Description
---------------- | -----------
`worker:build`   | Find and build all pending builds.
`worker:deploy`  | Find and deploy all pending releases.

### Convenience bins
Two convenience bash scripts are included to make it easier to set up cronjobs every 15 seconds, rather than every minute which cron supports.

If no arguments are provided, both scripts will fire immediately, then wait 30 seconds and fire again (0s and 30s marks).
If `--alt` is used as a parameter, the scripts will wait 15 seconds, fire, then wait 30 seconds and fire again (15s and 45s marks).

Command            | Description
------------------ | -----------
`bin/worker-build` | Bash script for builds
`bin/worker-push`  | Bash script for pushes

### Crontab example
```
# default worker runs on 0 and 30
# --alt runs on 15 and 45

# Hal-agent Push Worker
* * * * * /var/hal9000agent/bin/worker-push
* * * * * /var/hal9000agent/bin/worker-push --alt

# Hal-agent Build Worker
* * * * * /var/hal9000agent/bin/worker-build
* * * * * /var/hal9000agent/bin/worker-build --alt

# Health checks (every 4 hours)
0 */4 * * * /var/hal9000agent/bin/hal docker:status
0 */4 * * * /var/hal9000agent/bin/hal server:connections
```

## Deployment

`bin/deploy` must be run when deploying to an environment, as this copies environment specific settings to `config.env.yml`.
For development deployments, create a `config.env.yml` using `environment/dev.yml` as a prototype.

Unix builds require a docker-supported build server. `boot2docker` can be used for this purpose.

## Development Setup

For local development, you will want to setup boot2docker or docker machine as your build server.

1. Add ssh key location to `%ssh.credentials%` in `config.env.yml` for docker cert file for docker VM.

  > You will likely want to add a hosts entry from `$(docker-machine ip $DOCKER_MACHINE_NAME)` to `$DOCKER_MACHINE_NAME`.
  > ```yaml
  >  ssh.credentials:
  >     - ['%build.unix.remoteUser%', '*', 'key:/Users/$USERNAME/.docker/machine/machines/$DOCKER_MACHINE_NAME/id_rsa']
  >     - # unix build server credentials
  >     - # windows build server credentials
  >     - # rsync credentials
  > ```
  > Don't forget to replace `$USERNAME` and `DOCKER_MACHINE_NAME`.

2. Setup docker host

    > Run the following command:  
    > `bin/util/prepare-docker-builder`
    >
    > Alternatively, run the following manually:  
    >
    > 1. `tce-load -wi rsync` (Install rsync)
    > 2. `mkdir /var/hal9000 && sudo chown docker:docker /var/hal9000` (Create temp)
    > 3. `mkdir /docker-images && sudo chown docker:docker /docker-images` (Create image source)

### Unix Build Server Preparation

- `$user` is the dedicated user that runs the agent (Example: `hal9000test`).
- `$syncer` is the user code is rsynced through (Example: `codexfer`).

1. Agent server setup
    * `/var/hal9000agent` must exist and be owned by `$syncer:hal-agent`.
    * Deploy agent to **agent server**.
    * **$user** must be able to ssh (passwordless) to the dedicated **build server**.
    * **$user** must be able to ssh (passwordless) as **$syncer** to all deploy web/app servers.
    * `/tmp/hal9000` must exist and be owned by **$user**, it should be allocated **~10GB**.
         - This is a temporary space while builds are running.
    * `/builds/hal9000` must exist and be owned by **$user**. (`/builds/hal9000test` for test environment)
2. Build server setup
    * Docker must be installed (>=1.5).
    * **$user** must be able to sudo docker.
    * `/var/hal9000` must exist and be owned by **$user**, it should be allocated **~10-20GB**.
        - Docker images are stored here, as well as a temporary space while builds are running.
    * `/docker-images` must exist and be owned by **$user**.
    * Disable the following option in `sudoers`: `Defaults requiretty`
    * Allow **$user** to sudo docker on build box:
        - `$user $SERVERNAME=(root) NOPASSWD:SETENV: /bin/docker,/usr/bin/docker,/usr/bin/du`
        - `/usr/bin/du` for agent health checks.
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
- `docker:$image` (Remotely run through docker with custom docker image `$image`)
- `windows` (Remotely run)

Deployment Systems:
- Rsync
- Code Deploy
- Elastic Beanstalk
- Script

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
HAL_ENVIRONMENT  | Environment (such as `staging`, `prod`)
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

The following environment variables are available to application `build_transform`, `pre_push` and `post_push` scripts:

Variable         | Description
---------------- | -----------
HAL_HOSTNAME     | Hostname of server
HAL_PATH         | Full path of application on server
HAL_BUILDID      | ID of the build
HAL_COMMIT       | 40 character commit SHA
HAL_GITREF       | Git reference (such as `master`)
HAL_ENVIRONMENT  | Environment (such as `test`, `beta`, `prod`)
HAL_REPO         | Hal project name for the deployed application
