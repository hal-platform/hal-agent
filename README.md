HAL 9000 Console Application
============================

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
vendor/bin/console [command]
```

When installed stand-alone, the application can be run as follows:
```
bin/console [command]
```

Help for any command may be viewed by adding the --help flag as follows.
```
bin/console [command] [options] --help
```

## Available Commands

Command        | Description
-------------- | ----------
`build:create` | Create a build job for an application based on an environment
`build:build`  | Download, build, and archive a build

## Not Implemented

Command          | Description
---------------- | ----------
`push:create`    | Create a push job for an application based on a build and deployment
`push:push`      | Push a built application to a server
`build:list`     | List all existing builds.
`build:remove`   | Remove archive for a build.
`build:package`  | Package an existing build for use elsewhere.
