# Contents
- [Contributing](#contributing)
- [Code Standards](#code-standards)
  - [PHP](#php)
    - [String Interpolation](#string-interpolation)
    - [Naming Conventions](#naming-conventions)
    - [Typing (Type Hints and Return Types)](#typing-type-hints-and-return-types)
  - [YAML](#yaml)
    - [Naming Conventions](#naming-conventions-1)
    - [List Syntax](#list-syntax)
  - [Twig](#twig)
    - [Naming Conventions](#naming-conventions-2)
- [Development Processes](#development-processes)
  - [Dependency Development](#dependency-development)

# Contributing
Please first take the time to discuss any change(s) you wish to make with the owners of this repository (preferably via issue) before proceeding with making the change(s).

# Code Standards
## PHP
We use PHP for the majority of our codebase.

### String Interpolation

- Avoid sprintf:
  ```php
  $variable = sprintf("Some string here: %s", $variable2);
  ```
- Use single quotes for string literals unless string interpolation is explicitly needed:
  ```php
  $variable = 'This is an error message';
  ```
- Use double quotes and wrap variables in curly brackets:
  ```php
  $variable = "This string is interpolated\nValue: ${variable2}";
  $variable = "This string is interpolated\nValue: {$this->variable3}";
  ```

### Naming Conventions
- Avoid abbreviations:
  - `$language` instead of `$lang`
  - `$application` instead of `$app`
  - `$environment` instead of `$env`
- Acronyms must always use the same case:
  - `$applicationID` instead of `$applicationId`
  - `$httpClient` instead of either `$HTTPClient` or `$hTTPClient`
  - `$cachedHTTPClient` instead of `$cachedHttpClient`

### Typing (Type Hints and Return Types)
- Do not declare strict types
- For **public** methods, define scalar type hints and return types
- For **private** methods, do not define scalar type hints and return types
  - For private methods within **traits**, define scalar type hints and return types
  - For more complicated private functions, type hints and return types may be added. This should not be required often.
- Class constant visibility can be used at your disretion

## YAML
We utilize YAML for Symfony DI

### Naming Conventions
- Every service and parameter must be defined in lowercase
- Every service and parameter must be quoted with single quotes
- Separate domains with periods "."
  - `doctrine.cache`
  - `doctrine.cache.memory`
- Use underscore "_" to delimit compound words
  - `doctrine.config.dev_mode`
- Try to avoid more than 3 periods in a service or variable name.

### List syntax
- Arguments
  - If 2 arguments or fewer: Inline the argument definition to a single line
  - If more than 2 arguments: Each argument goes on a separate line
  - Example:
    ```yaml
    example_service:
      class: 'QL\MyProject\3ArgExample'
      arguments:
        - '@service1'
        - '@service2'
        - '%param1%'
    example_service_2:
      class: 'QL\MyProject\2ArgExample'
      arguments: ['@service1', '%param1%']
    ```

## Twig
We use Twig as our templating engine

### Naming Conventions
- Use snake case for variables, functions, and macros
  - Example
    ```php
    /* ExampleTwigExtension.php */
    $function = new Twig_Function('example_function', function () { return 'example'; });
    ```
    ```twig
    <!-- example.twig -->
    {% macro example_macro(example_argument) %}
      {% if example_function() is same as(example_argument) %}
        <span>{{ example_argument }}</span>
      {% endif %}
    {% endmacro %}
    ```

# Development Processes
## Dependency Development
- During development on a dependency, add the local copy to your `composer.json`
  - Do not include the `composer.json` changes in your Pull Request
  - Example:
    ```yaml
    # Add local development copy of hal-core as repository (https://getcomposer.org/doc/05-repositories.md#path)
    "repositories": [
        { "type": "path", "url": "../hal-core" }
    ],

    # Use inline alias to ensure composer finds your changes (https://getcomposer.org/doc/articles/aliases.md#require-inline-alias)
    "require": {
        "hal-core": "dev-branchname as 2.12.0"
    }
    ```
