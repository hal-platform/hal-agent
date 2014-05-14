## HAL 9000 Console Application

The HAL 9000 Console Application can perform various build and deploy operations and replaces the previous
monolithic pusher script. The build and deploy processes are now separate - in order to deploy an application, it
must first be built for the correct environment, a build ID obtained, and then that build ID can be deployed to
one or more servers or retrieved through the API for use elsewhere.

Under normal operations, a user will interact with the build system through the HAL 9000 web application - this
application will then be called in the background. It can also be interacted with through the HAL 9000 web API to
allow for triggered builds and deploys from a CI server. It will also be possible to obtain a completed build as a
package for deployment elsewhere (AWS, etc).

When installed with composer, the application can be run as follows:
```
vendor/bin/hal [command]
```

When installed stand-alone, the application can be run as follows:
```
bin/hal [command]
```

Help for any command may be viewed by adding the --help flag as follows.
```
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

## Worker Commands

These commands can be set on a timer or cron to pick up and process waiting actions.

Command          | Description
---------------- | -----------
`worker:build`   | Find and build all waiting builds.
`worker:push`    | Find and push all waiting pushes.

## Application available environment

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

Filename `.hal9000.yml`
```yaml
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

Required services:

This application requires several Doctrine ORM services as well as environment-based parameters. 

### Standalone

`bin/deploy` must be run when deploying to an environment, as this copies environment specific settings to `config.env.yml`.
For development deployments, create a `config.env.yml` using `config.env.yml.dist` as a prototype.

In standalone deployment, the required services will be imported from `imported.yml`, and environment-based parameters
will be loaded from `config.env.yml`.

### As a dependency

Neither `imported.yml` or `config.env.yml` will be loaded when run as a dependency. Instead, the parent application must
set an environment variable before running the hal executable.

Hal Agent will look for a file at `HAL_AGENT_CONFIG`. This should be a fully qualified path to a symfony dependency
injection configuration with the required services and parameters.

### Required services

Services          | Parameters
----------------- | ----------
doctrine.em       | agent.environment.archive
repository.repo   | agent.environment.path
environment.repo  | agent.environment.temp
user.repo         | agent.environment.home
deployment.repo   | agent.ssh-user
push.repo         | github.token
                  | github.baseurl
                  | mcp-logger.host

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
