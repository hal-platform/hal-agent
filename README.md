[![CircleCI](https://img.shields.io/circleci/project/github/hal-platform/hal-agent.svg?label=circleci)](https://circleci.com/gh/hal-platform/hal-agent)
[![Latest Version](https://img.shields.io/packagist/vpre/hal/hal-agent.svg?label=latest)](https://packagist.org/packages/hal/hal-agent)
[![Latest Stable Version](https://img.shields.io/packagist/v/hal/hal-agent.svg?label=stable)](https://packagist.org/packages/hal/hal-agent)
[![GitHub License](https://img.shields.io/github/license/hal-platform/hal-agent.svg)](https://packagist.org/packages/hal/hal-agent)
![GitHub Language](https://img.shields.io/github/languages/top/hal-platform/hal-agent.svg)
![GitHub Activity](https://img.shields.io/github/last-commit/hal-platform/hal-agent.svg)

# Hal Deployment Platform - Job Runner

Hal development is supported by [Quicken Loans](https://github.com/quickenloans).

> **Please Note: This codebase is under heavy development!**
>
> We are hard at work on Hal 3.0 which improves the stability and long-term architecture of Hal.

Hal is a **configuration and deployment management platform** for private datacenters, AWS, and more.

It contains a Web UI/API and agent for running jobs and long-running tasks.
**This repository is for the agent.**

Under normal operations, a user will interact with the system through the Hal frontend UI or API - this
application will then be called in the background.

Table of Contents:
- [Usage](#usage)
- [Available Commands](#available-commands)
- [Running on a server (for production use)](#running-on-a-server-for-production-use)
- [Running locally (for development)](#running-locally-for-development)

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
`job:build`                   | Create and run a build job.
`job:release`                 | Create and run a release job.
`management:build:remove`     | Remove a build (delete from the filesystem)
`runner:deploy`               | Deploy a release.
`runner:build`                | Run a build.

## Running on a server (for production use)

TBD. We'll build out this section once Hal 3.0 is closer to release.

## Running locally (for development)

TBD. We'll build out this section once Hal 3.0 is closer to release.
