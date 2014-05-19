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

## Application scripting environment

### On Build

During the build process, The following environment variables are available to application build scripts:

Variable         | Description
---------------- | -----------
HOME             | Home Directory
PATH             | Global include path
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
PATH             | Global include path
HAL_BUILDID      | ID of the build
HAL_COMMIT       | 40 character commit SHA
HAL_GITREF       | Git reference (such as `master`)
HAL_ENVIRONMENT  | Environment (such as `test`, `beta`, `prod`)
HAL_REPO         | Hal name for the deployed application

## Deployment

This application requires several Doctrine ORM services as well as environment-based parameters.

### Standalone

`bin/deploy` must be run when deploying to an environment, as this copies environment specific settings to `config.env.yml`.
For development deployments, create a `config.env.yml` using `config.env.yml.dist` as a prototype.

In standalone deployment, the required services will be imported from `imported.yml`, and environment-based parameters
will be loaded from `config.env.yml`.

### As a dependency

Neither `imported.yml` or `config.env.yml` will be loaded when installed as a dependency. Instead, the parent
application must set an environment variable before running the hal executable.

Hal Agent will look for a file at `HAL_APPLICATION_CONFIG`. This should be a fully qualified path to a symfony dependency
injection configuration with the required services and parameters.

For this reason it is highly recommended when using the agent as a dependency, a new script should be created
that sets the environment variable, and calls the actual hal-agent bin.

Example:
```bash
#!/usr/bin/env bash
DIR=$( cd "$( dirname "$0" )" && pwd )

export HAL_APPLICATION_CONFIG="$DIR/../app/config.yml"
"$DIR/../vendor/bin/hal" $?
```

### Required configuration

Key                       | Type      | Description
------------------------- | --------- | -----------
doctrine.em               | Service   | Doctrine Entity Manager
repository.repo           | Service   | Doctrine Entity Repository
environment.repo          | Service   | Doctrine Entity Repository
user.repo                 | Service   | Doctrine Entity Repository
deployment.repo           | Service   | Doctrine Entity Repository
push.repo                 | Service   | Doctrine Entity Repository
agent.environment.archive | Parameter | Path to permanent archive directory for successful builds
agent.environment.temp    | Parameter | Path to temporary build directory
agent.environment.path    | Parameter | System PATH
agent.environment.home    | Parameter | System HOME
agent.ssh-user            | Parameter | Username for rsync to servers
agent.email.subjects      | Parameter | Templates for email and log messages
github.token              | Parameter | Github authentication token
github.baseurl            | Parameter | Github url
mcp-logger.host           | Parameter | Core logger hostname

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
`symfony/dependency-injection` | Dependency injection and service container
`symfony/debug`                | Convert errors to exceptions
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
