## HAL 9000 Console Application

The HAL 9000 Console Application can perform various build and deploy operations and replaces the previous
monolithic pusher script. The build and deploy processes are now separate - in order to deploy an application, it
must first be built for the correct environment, a build ID obtained, and then that build ID can be deployed to
one or more servers or retrieved through the API for use elsewhere.

Under normal operations, a user will interact with the build system through the HAL 9000 web application - this
application will then be called in the background. It can also be interacted with through the HAL 9000 web API to
allow for triggered builds and deploys from a CI server. It will also be possible to obtain a completed build as a
package for deployment elsewhere (AWS, etc).

Table of Contents:
* [Usage](#usage)
* [Available Commands](#available-commands)
* [Worker Commands](#worker-commands)
* [Application scripting environment](#application-scripting-environment)
* [Deployment](#deployment)
* [Configuration](#configuration)
* [Dependencies](#dependencies)
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
# filename: APPLICATION_ROOT/.hal9000.yml

id: ''      # Build ID
source: ''  # Full url of github repository
env: ''     # Environment of the build
user: ''    # Username of user that triggered the push
branch: ''  # Git Reference
commit: ''  # Git commit SHA
date: ''    # ISO 8601 date
```

The following environment variables are available to application pre and post push scripts:

Variable         | Description
---------------- | -----------
HAL_HOSTNAME     | Hostname of server
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
email.subjects            | Templates for email and log subjects
email.notify              | A list of secondary email addresses to notify
environment.archive       | Path to permanent archive directory for successful builds
environment.temp          | Path to temporary build directory
environment.path          | System PATH
environment.home          | System HOME
push.sshUser              | Username used to ssh to servers for syncing code
github.token              | Github Enterprise authentication token
github.com.token          | Github.com authentication token
github.baseurl            | Github url

A note on `agent.email.subjects`:

The subject of email and log messages is customizable by providing an associative array containing the replaced templates.

The following subjects are available:
- `email.build`
- `email.push`
- `log.build`
- `log.push`

The following tokens are available:
- `buildId`
- `pushId`
- `github`
- `repository`
- `server`
- `environment`
- `status`

Example usage:

```yaml
# config.yml
email.subjects:
    email.build: '{status} - {repository} ({environment})'
    log.push: '{status} - {repository} ({server}) - Push {pushId}'
```

## Dependencies

This application has a lot of dependencies. Here are a list of what they are used for.

Package                        | Description
------------------------------ | -----------
`knplabs/github-api`           | Download code from github and resolve git references.
`monolog/monolog`              | Log handling
`psr/log`                      | Logging standard
`ql/hal-core`                  | Domain model
`ql/mcp-core`                  | Core utilities
`ql/mcp-logger`                | Logging to core logger
`swiftmailer/swiftmailer`      | Emailer
`symfony/config`               | Cascading configuration
`symfony/console`              | The core of this application
`symfony/debug`                | Convert errors to exceptions
`symfony/dependency-injection` | Dependency injection and service container
`symfony/event-dispatcher`     | Event dispatching for the console application
`symfony/filesystem`           | Filesystem abstraction
`symfony/monolog-bridge`       | Console output of log messages
`symfony/process`              | System process abstraction

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
