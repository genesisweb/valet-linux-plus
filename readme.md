<p align="center"><img width="500" src="https://valetlinux.plus/valet-logo.png"></p>

<p align="center">
<a href="https://travis-ci.org/genesisweb/valet-linux-plus"><img src="https://travis-ci.org/genesisweb/valet-linux-plus.svg?branch=master" alt="Build Status"></a>
<a href="https://github.styleci.io/repos/218757845"><img src="https://github.styleci.io/repos/218757845/shield?branch=master" alt="StyleCI"></a>
<a href="https://scrutinizer-ci.com/g/genesisweb/valet-linux-plus/?branch=master"><img src="https://scrutinizer-ci.com/g/genesisweb/valet-linux-plus/badges/quality-score.png?b=master" alt="Scrutinizer"></a>
<a href="https://packagist.org/packages/genesisweb/valet-linux-plus"><img src="https://poser.pugx.org/genesisweb/valet-linux-plus/downloads.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/genesisweb/valet-linux-plus"><img src="https://poser.pugx.org/genesisweb/valet-linux-plus/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/genesisweb/valet-linux-plus"><img src="https://poser.pugx.org/genesisweb/valet-linux-plus/v/unstable.svg" alt="Latest Unstable Version"></a>
<a href="https://packagist.org/packages/genesisweb/valet-linux-plus"><img src="https://poser.pugx.org/genesisweb/valet-linux-plus/license.svg" alt="License"></a>
</p>

## Introduction

Valet *Linux+* is an advanced development environment for Linux minimalists. No Vagrant, no `/etc/hosts` file. You can even share your sites publicly using local tunnels. _Yeah, we like it too._

Valet *Linux+* configures your system to always run Nginx in the background when your machine starts. Then, using [DnsMasq](https://en.wikipedia.org/wiki/Dnsmasq), Valet proxies all requests on the `*.test` domain to point to sites installed on your local machine.

In other words, a blazing fast PHP development environment that uses roughly 7mb of RAM. Valet *Linux+* isn't a complete replacement for Vagrant or Homestead, but provides a great alternative if you want flexible basics, prefer extreme speed, or are working on a machine with a limited amount of RAM.

## Official Documentation

Documentation for Valet can be found on the [Valet Linux website](https://valetlinux.plus/).

## License

Laravel Valet is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
