<p align="center"><img width="500" src="https://genesisweb.co.in/images/brand/valet-logo.png"></p>

<p align="center">
<a href="https://travis-ci.org/genesisweb/valet-linux-plus"><img src="https://travis-ci.org/genesisweb/valet-linux-plus.svg?branch=master" alt="Build Status"></a>
<a href="https://packagist.org/packages/genesisweb/valet-linux-plus"><img src="https://poser.pugx.org/genesisweb/valet-linux-plus/downloads.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/genesisweb/valet-linux-plus"><img src="https://poser.pugx.org/genesisweb/valet-linux-plus/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/genesisweb/valet-linux-plus"><img src="https://poser.pugx.org/genesisweb/valet-linux-plus/v/unstable.svg" alt="Latest Unstable Version"></a>
<a href="https://packagist.org/packages/genesisweb/valet-linux-plus"><img src="https://poser.pugx.org/genesisweb/valet-linux-plus/license.svg" alt="License"></a>
</p>

## Introduction

Valet Linux+ is a development environment for Linux. No Vagrant, no Docker, no `/etc/hosts` file.

Since Valet Linux+ is intended to replace Valet, it still uses the same `valet` command-line name. Any changes in its interface are documented below.

### Differences from Valet Linux

Here are a few key differences compared to the original Valet:

- PHP switch versions with CLI 
- MySQL (with optimized configuration)
- Redis
- Subdomains
- Remote access without `valet share`
- Many more features outlined below...

## Table of Contents

<!-- START doctoc generated TOC please keep comment here to allow auto update -->
<!-- DON'T EDIT THIS SECTION, INSTEAD RE-RUN doctoc TO UPDATE -->
- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installation](#installation)
  - [Serving sites](#serving-sites)
- [Switching PHP version](#switching-php-version)
- [Database](#database)
- [Redis](#redis)
- [Securing Sites With TLS](#securing-sites-with-tls)
- [Sharing Sites on LAN](#sharing-sites-on-lan)

<!-- END doctoc generated TOC please keep comment here to allow auto update -->

## Requirements

### Ubuntu

| Requirement       | Description                                                               |
| ----------------- | ------------------------------------------------------------------------- |
| Ubuntu version    | 14.04+                                                                    |
| OS packages       | `sudo apt-get install network-manager libnss3-tools jq xsel`              |
| PHP version       | 7.1+                                                                      |
| PHP extensions    | php*-cli php*-curl php*-mbstring php*-mcrypt php*-xml php*-zip            |

*Replace the star * with your php version.*

#### Ubuntu 14.04
In order to use the `valet secure` command in Ubuntu 14.04 you need to add the nginx PPA because the version in the Trusty repos does not support `http2`.

To add the nginx ppa:
```
sudo add-apt-repository -y ppa:nginx/stable
sudo apt-get update
```


## Installation

1. Install or update PHP to 7.1+ version.
2. Install [Composer](http://getcomposer.org) from official website.
3. Install Valet Linux+ with Composer via `composer global require genesisweb/valet-linux-plus`.
4. Add `export PATH="$PATH:$HOME/.composer/vendor/bin"` to `.bash_profile`.
8. Run the `valet install` command. This will configure and install Valet Linux+ and DnsMasq, and register Valet's daemon to launch when your system starts.
9. Once Valet Linux+ is installed, try pinging any `*.test` domain on your terminal using a command such as `ping -c1 foobar.test`. If Valet Linux+ is installed correctly you should see this domain responding on `127.0.0.1`. If not you might have to restart your system.

> :information_source: Valet Linux+ will automatically start its daemon each time your machine boots. There is no need to run `valet start` or `valet install` ever again once the initial Valet Linux+ installation is complete.

> :information_source: To update Valet Linux+ to the latest version use the `composer global require genesisweb/valet-linux-plus` command in your terminal. After upgrading, it is good practice to run the `valet install` command so Valet Linux+ can make additional upgrades to your configuration files if necessary.

### Serving sites

Once Valet Linux+ is installed, you're ready to start serving sites. Valet Linux+ provides a command to help you serve your sites: `valet park`. Which will register the current working directory as projects root. Generally this directory is `~/sites`.

1. Create a `sites` directory: `mkdir ~/sites`
2. `cd ~/sites`
3. `valet park`

That's all there is to it. Now, any project you create within your "parked" directory will automatically be served using the http://folder-name.test convention.

For example:

1. `mkdir ~/sites/example`
2. `cd ~/sites/example`
3. `echo "<?php echo 'Valet Linux+ at your service';" > index.php`
4. Go to `http://example.test`, you should see `Valet Linux+ at your service`

## Switching PHP version

Switch PHP version using one of five commands:

```
valet use 7.1
```

```
valet use 7.2
```

```
valet use 7.3
```

```
valet use 7.4
```

Use `--update-cli` flag to update PHP cli version as well.

## Database
Valet Linux+ automatically installs MySQL 5.7 with 5.6 compatibility mode included. It includes a tweaked `my.cnf` which is aimed at improving speed.

Username: `root`

Password: `root`

## Change password

```
valet db:password <old> <new>
```


## List databases

```
valet db:list
```

### Creating database

Create database using:

```
valet db:create <name>
```

When no name is given it'll try to find the closest git repository directory name. When it can't find one it'll use the current working directory name.

```
valet db:create
```

### Dropping database

Drop a database using:

```
valet db:drop <name>
```

When no name is given it'll try to find the closest git repository directory name. When it can't find one it'll use the current working directory name.

```
valet db:drop
```

### Resetting database

Drop and create a database using:

```
valet db:reset <name>
```

When no name is given it'll try to find the closest git repository directory name. When it can't find one it'll use the current working directory name.

```
valet db:reset
```

### Exporting database

Export a database:

```
valet db:export <database>
```

When no database name is given it'll use the current working directory name.

All database exports are gzipped. You can still export SQL file using `--sql` flag as shown below

```
valet db:export <database> --sql
```

### Importing database

Import a database with progress bar

```
valet db:import <database_name> <filename>.sql
```

When no name is given it'll try to find the closest git repository directory name. When it can't find one it'll use the current working directory name.

You can import `.sql` directly as well as gzipped `.sql.gz` database exports.

## Subdomains

You can manage subdomains for the current working directory using:

```
valet subdomain:list
```

```
valet subdomain:add <subdomain>
```

For example:

```
valet subdomain:add welcome
```

Will create `welcome.yourproject.test`.

## Domain Alias / Symlinks

Display all of the registered symbolic links based on the current folder.:

```
valet links
```

Add new alias:
```
valet link <domain>
```

For example:

```
valet link yourproject2
```

Will create a symbolic link to the current folder `yourproject2.test`.

Remove alias:
```
valet unlink <domain>
```

For example:

```
valet unlink yourproject2
```

## Mailhog

Mailhog is used to catch emails send from PHP. You can access the panel at [http://mailhog.test](http://mailhog.test).

**Enable Mailhog:

```**
valet start mailhog
```

Disable Mailhog:

```
valet stop mailhog
```

## Redis

Redis is automatically installed and listens on the default port `6379`. The redis socket is located at `/tmp/redis.sock`

Enable Redis:

```
valet start redis
```

Disable Redis:

```
valet stop redis
```

## Securing Sites With TLS

By default, Valet serves sites over plain HTTP. However, if you would like to serve a site over encrypted TLS using HTTP/2, use the secure command. For example, if your site is being served by Valet on the example.test domain, you should run the following command to secure it:

```
valet secure example
```

To "unsecure" a site and revert back to serving its traffic over plain HTTP, use the `unsecure` command. Like the `secure` command, this command accepts the host name you wish to unsecure:

```
valet unsecure example
```

## Sharing Sites on LAN

By default, Valet Linux+ sites provide support to access your valet site on LAN access, it can be helpful to access site on different devices with your lan IP address, you don't have to do any additional changes. just open your LAN IP address instead of localhost and you will be able to see your available site lists. you may can find your LAN IP address via `ifconfig` command.

```
http://192.168.0.2/valet-sites
```

**Note:** As we can see the domain is changed to your remote IP address, so it won't be possible to run the site on SSL same as we run it with dedicated domain.


## Log locations

The `nginx-error.log`, `php.log` and `mysql.log` are located at `~/.valet/Log`.

Other logs are located at `/usr/local/var/log`

## PHP.ini location

The PHP.ini location is `/usr/local/etc/valet-php/VERSION/php.ini`.

## Valet drivers
Valet uses drivers to handle requests. You can read more about those [here](https://laravel.com/docs/5.4/valet#custom-valet-drivers).

By default these are included:

- CakePHP 3
- Craft
- Drupal
- Jigsaw
- Laravel
- Lumen
- Magento
- Magento 2
- Neos
- Pimcore 5
- Shopware 5
- Slim
- Statamic
- Static HTML
- Symfony
- Typo3
- WordPress / Bedrock
- Zend Framework

A full list can be found [here](cli/drivers).

## Custom Valet Drivers

You can write your own Valet "driver" to serve PHP applications running on another framework or CMS that is not natively supported by Valet. When you install Valet Linux+, a `~/.valet/Drivers` directory is created which contains a `SampleValetDriver.php` file. This file contains a sample driver implementation to demonstrate how to write a custom driver. Writing a driver only requires you to implement three methods: `serves`, `isStaticFile`, and `frontControllerPath`.

All three methods receive the `$sitePath`, `$siteName`, and `$uri` values as their arguments. The `$sitePath` is the fully qualified path to the site being served on your machine, such as `/Users/Lisa/Sites/my-project`. The `$siteName` is the "host" / "site name" portion of the domain (`my-project`). The `$uri` is the incoming request URI (`/foo/bar`).

Once you have completed your custom Valet Linux+ driver, place it in the `~/.valet/Drivers` directory using the `FrameworkValetDriver.php` naming convention. For example, if you are writing a custom valet driver for WordPress, your file name should be `WordPressValetDriver.php`.

Let's take a look at a sample implementation of each method your custom Valet Linux+ driver should implement.

#### The `serves` Method

The `serves` method should return `true` if your driver should handle the incoming request. Otherwise, the method should return `false`. So, within this method you should attempt to determine if the given `$sitePath` contains a project of the type you are trying to serve.

For example, let's pretend we are writing a `WordPressValetDriver`. Our serve method might look something like this:

```
/**
 * Determine if the driver serves the request.
 *
 * @param  string  $sitePath
 * @param  string  $siteName
 * @param  string  $uri
 * @return bool
 */
public function serves($sitePath, $siteName, $uri)
{
    return is_dir($sitePath.'/wp-admin');
}

```

#### The `isStaticFile` Method

The `isStaticFile` should determine if the incoming request is for a file that is "static", such as an image or a stylesheet. If the file is static, the method should return the fully qualified path to the static file on disk. If the incoming request is not for a static file, the method should return `false`:

```
/**
 * Determine if the incoming request is for a static file.
 *
 * @param  string  $sitePath
 * @param  string  $siteName
 * @param  string  $uri
 * @return string|false
 */
public function isStaticFile($sitePath, $siteName, $uri)
{
    if (file_exists($staticFilePath = $sitePath.'/public/'.$uri)) {
        return $staticFilePath;
    }

    return false;
}

```

> {note} The `isStaticFile` method will only be called if the `serves` method returns `true` for the incoming request and the request URI is not `/`.

#### The `frontControllerPath` Method

The `frontControllerPath` method should return the fully qualified path to your application's "front controller", which is typically your "index.php" file or equivalent:

```
/**
 * Get the fully resolved path to the application's front controller.
 *
 * @param  string  $sitePath
 * @param  string  $siteName
 * @param  string  $uri
 * @return string
 */
public function frontControllerPath($sitePath, $siteName, $uri)
{
    return $sitePath.'/public/index.php';
}

```

### Local Drivers

If you would like to define a custom Valet driver for a single application, create a `LocalValetDriver.php` in the application's root directory. Your custom driver may extend the base `ValetDriver` class or extend an existing application specific driver such as the `LaravelValetDriver`:

```
class LocalValetDriver extends LaravelValetDriver
{
    /**
     * Determine if the driver serves the request.
     *
     * @param  string  $sitePath
     * @param  string  $siteName
     * @param  string  $uri
     * @return bool
     */
    public function serves($sitePath, $siteName, $uri)
    {
        return true;
    }

    /**
     * Get the fully resolved path to the application's front controller.
     *
     * @param  string  $sitePath
     * @param  string  $siteName
     * @param  string  $uri
     * @return string
     */
    public function frontControllerPath($sitePath, $siteName, $uri)
    {
        return $sitePath.'/public_html/index.php';
    }
}
```
