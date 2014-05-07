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
vendor/bin/hal-agent [command]
```

When installed stand-alone, the application can be run as follows:
```
bin/hal-agent [command]
```

Help for any command may be viewed by adding the --help flag as follows.
```
bin/hal-agent [command] [options] --help
```

## Available Commands

Command          | Description
---------------- | ----------
`build:create`   | Create a build job for an application based on an environment
`build:build`    | Download, build, and archive a build
`build:remove`   | Remove archive for a build.
`push:create`    | Create a push job for an application based on a build and deployment
`push:push`      | Push a built application to a server
`builds:list`    | List all existing builds.

## Not Implemented

Command          | Description
---------------- | ----------
`build:package`  | Package an existing build for use elsewhere.

## Testing

The porcelain commands can be used to create and build entities in a single process:

Build example:
```
bin/hal-agent build:build $(bin/hal-agent build:create REPOSITORY_ID ENVIRONMENT_ID GIT_REFERENCE --porcelain)
bin/hal-agent b:b $(bin/hal-agent b:c REPOSITORY_ID ENVIRONMENT_ID master --porcelain)
```

Push example:
```
bin/hal-agent push:push $(bin/hal-agent push:create BUILD_ID DEPLOYMENT_ID --porcelain)
bin/hal-agent p:p $(bin/hal-agent p:c BUILD_ID DEPLOYMENT_ID --porcelain)
```

## Deployment

`bin/deploy` must be run when deploying to an environment, as this copies environment specific settings to `config.env.yml`
