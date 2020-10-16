
# Contao Composer Plugin

[![Version](http://img.shields.io/packagist/v/contao-community-alliance/composer-plugin.svg?style=flat-square)](https://packagist.org/packages/contao-community-alliance/composer-plugin)
[![License](http://img.shields.io/packagist/l/contao-community-alliance/composer-plugin.svg?style=flat-square)](http://spdx.org/licenses/LGPL-3.0+)
[![Downloads](http://img.shields.io/packagist/dt/contao-community-alliance/composer-plugin.svg?style=flat-square)](https://packagist.org/packages/contao-community-alliance/composer-plugin)

The Contao Composer plugin is responsible for correctly installing Contao 3 extensions in a Composer environment.
Composer files are always located in the `vendor` folder, but they must be copied/symlinked to `system/modules`
to be correctly detected by Contao.

Be aware that this plugin is not necessary for Contao 4 / Symfony bundles. If you however do support both Contao 3
and Contao 4, make sure to follow the *old* way which is still fully supported in Contao 4. You will obviously
not be able to use new bundle features (like the DIC) though.


## composer.json

Follow the [Composer manual][composer_libraries] on how the basic `composer.json` in your library should look like.

A few simple rules will make your package compatible with Contao 3:

 1. Change the type of your package to `contao-module`
 2. Add a requirement for the correct Contao version
 3. Add a requirement to the Contao Composer plugin
 4. Add the sources to the `extras => contao` section
 5. If necessary, specify your runonce files
 
**Example:**

```json
{
    "name": "vendor/package-name", 
    "type": "contao-module",
    "license": "LGPL-3.0+",
    "require": {
        "contao/core-bundle": "4.*",
        "contao-community-alliance/composer-plugin": "3.*"
    },
    "extra": {
        "contao": {
            "sources": {
                "": "system/modules/extension-name"
            },
            "runonce": [
                "config/update.php"
            ]
        }
    },
    "replace": {
        "contao-legacy/extension-name": "self.version"
    }
}
```


### Require Contao

In Contao 4, the bundled modules were restructured so each module is a separate Symfony bundle. This means that
 a user can decide not to install the news or calendar extension if it's not needed for their system.

Your extension should always `require contao/core-bundle` in the appropriate version (see *About semantic versioning*).
If your code is extending other modules like news or calendar, make sure to add correct requirements for the 
respective Symfony bundles (e.g. `contao/news-bundle` or `contao/calendar-bundle`).

Contao 4 was designed to be backwards-compatible with Contao 3. Therefore, it is very much possible to have an
extension that does support both Contao 3 and Contao 4. If your extension works with Contao 3.5 and Contao 4, a correct
requirement could look like this:

```json
{
    "require": {
        "contao/core-bundle": "~3.5 || ~4.1"
    }
}
```


### Require the Contao Composer Plugin

There are two different versions of the Plugin currently actively supported.

 - Version 2 of the plugin is made for Contao 3. It supports installing Composer packages in `TL_ROOT/composer`
   instead of the root directory of your installation.
   
 - Version 3 of the plugin is made for Contao 4. In Contao 4, the `vendor` folder is located in your installation
   root, and Contao is just another dependency of your installation.
   
Make sure to require the correct version of the plugin. If your module does support Contao 3 and Contao 4, the correct
require statement looks like this:

```json
{
    "require": {
        "contao-community-alliance/composer-plugin": "~2.4 || ~3.0"
    }
}
```

Versions older than 2.4 will not support `contao/core-bundle`, so make sure to set a correct dependency. If you only
support Contao 4, the required version would simply be `3.*`. However, you should probably create a Symfony bundle
and not require the Contao Composer Plugin at all…

Be aware that your root project (the `composer.json` in your root folder) should be of type *project*, otherwise
the plugin will not install Contao sources.


### Sources

In the sources section, you can define which files should be copied where on installation. This is necessary
for Contao 3 extensions to be installed in the `system/modules` folder.

If your GIT repository contains only files that should be copied into the `system/modules/extension-name` folder,
simply specify an empty source and the target folder.

```json
{
    "extra": {
        "contao": {
            "sources": {
                "": "system/modules/extension-name"
            }
        }
    }
}
```

However, you could also restructure your GIT so that Contao files live in their own folder. In this case,
you can use the Composer autoloader and follow [PSR-0][psr0] or [PSR-4][psr4] namespaces. However, your extension 
is then incompatible with the old extension repository.

You can define multiple files or folder to be copied/symlinked into the Contao installation. Your package
can even install multiple Contao extensions at once.

```json
{
    "extra": {
        "contao": {
            "sources": {
                "config": "system/modules/extension-name/config",
                "dca": "system/modules/extension-name/dca",
                "templates": "system/modules/extension-name/templates"
            }
        }
    },
    "autoload": {
        "psr-4": {
            "VendorName\\ExtensionName\\": "src/"
        }
    }
}
```


### Userfiles

The `userfiles` property allows to copy files from a Composer package to the `/files` folder in Contao.
As this folder can be renamed in the Contao configuration, the installer automatically tries to find the correct location.

Be aware that userfiles are only copied once if they do not exist, they are not overwritten on an update.

```json
{
	"extra": {
		"contao": {
			"userfiles": {
				"src/system/modules/my-module/files/images": "my-module/images"
			}
		}
	}
}
```


### Runonces

Putting `runonce.php` files into your extension's `config` directory is a bad practice with Composer.
The file will be deleted by Contao after it's executed, which means Composer will complain about modified files
on the next update. To work around this, you can specify a list of runonce files in the `composer.json`. 
They will be executed after each installation or update, but they won't be deleted. There is no need to name 
them `runonce.php` either, feel free to use any other name.

```json
{
    "extra": {
        "contao": {
            "runonce": [
                "src/system/modules/extension-name/runonce/init_update.php",
                "src/system/modules/extension-name/runonce/do_db_update.php",
                "src/system/modules/extension-name/runonce/refresh_entities.php"
            ]
        }
    }
}
```

The runonce files will be executed in the order you specify them.


## About semantic versioning

Following [Semanting Versioning][semver] is crucial to the success of Composer installation. If you (the developer)
do not follow semantic versioning, it is very hard for others, (and the dependency manager) to install correct
versions of your library. 

You should also get familiar with the [Composer version constraints][composer_versions] to correctly set your
dependencies. Incorrect dependencies will lead to broken installations, and it's **always** the developer's fault!


## Server requirements

The Contao Composer Plugin requires an up-to-date server configuration. Contao 4 does no longer support the so-called
"safe mode hack", and the plugin now requires symlink support.



[composer_libraries]: https://getcomposer.org/doc/02-libraries.md
[composer_versions]: https://getcomposer.org/doc/articles/versions.md
[semver]: http://semver.org
[psr0]: http://www.php-fig.org/psr/psr-0/
[psr4]: http://www.php-fig.org/psr/psr-4/
