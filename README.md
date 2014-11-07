Contao Composer Plugin
======================
[![Version](http://img.shields.io/packagist/v/contao-community-alliance/composer-plugin.svg?style=flat-square)](https://packagist.org/packages/contao-community-alliance/composer-plugin)
[![Stable Build Status](http://img.shields.io/travis/contao-community-alliance/composer-plugin/master.svg?style=flat-square&label=stable build)](https://travis-ci.org/contao-community-alliance/composer-plugin)
[![Upstream Build Status](http://img.shields.io/travis/contao-community-alliance/composer-plugin/develop.svg?style=flat-square&label=dev build)](https://travis-ci.org/contao-community-alliance/composer-plugin)
[![License](http://img.shields.io/packagist/l/contao-community-alliance/composer-plugin.svg?style=flat-square)](http://spdx.org/licenses/LGPL-3.0+)
[![Downloads](http://img.shields.io/packagist/dt/contao-community-alliance/composer-plugin.svg?style=flat-square)](https://packagist.org/packages/contao-community-alliance/composer-plugin)

contao composer installer

Sources
--------

Contao require a strict module structure.
Composer install all dependencies into the `vendor` directory, using the complete repository structure.
Both often not match each other.

To solve this problem, without making a lot of shadow copies the `contao-module` installer create symlinks.

**Hint for users**: Symlinks require a *good* server setup. To work with Apache2 the `FollowSymLinks` option is mostly required.

**Hint for developers**: PHP will follow symlinks by default, but using `__DIR__` or `__FILE__` will return the real path.
You will get `composer/vendor/me/my-module/MyClass.php` instead of `system/modules/my-module/MyClass.php`.

**Hint for Windows users**: Symlinks on Windows require PHP 5.3+, but this is no real problem, because composer require PHP 5.3+ ;-)

Installing repository root as module `my-module`:
**Hint**: This is the implicit fallback (`system/modules/$packageName`), if no symlinks specified!
```json
{
	"extra": {
		"contao": {
			"sources": {
				"": "system/modules/my-module"
			}
		}
	}
}
```

Installing repository sub-path `src/system/modules/my-module` as module `my-module`:
```json
{
	"extra": {
		"contao": {
			"sources": {
				"src/system/modules/my-module": "system/modules/my-module"
			}
		}
	}
}
```

Userfiles
---------

Sometimes you need to provide user files (files within the `(tl_)files` directory).
There is a support to install these user files.
Normally these files can be modified by users.
To protect user changes, user files are only copied if they are not exists.
The installer will **never** overwrite a user file.

**Hint**: User files are installed into the `$uploadPath` directory, not `TL_ROOT`.

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

Runonces
--------

Putting your `runonce.php` into your modules `config` directory is not a good idea in combination with composer.
After contao runs the `runonce.php` it get deleted. Next time you do an update, composer complains about this modification.
To solve this, you can define your `runcone.php`'s, yes you are right, you can use multiple `runonce.php` files.
There is no need to name them `runonce.php`, feel free to use any other name.

```json
{
	"extra": {
		"contao": {
			"runonce": [
				"src/system/modules/my-module/runonce/init_update.php",
				"src/system/modules/my-module/runonce/do_db_update.php",
				"src/system/modules/my-module/runonce/refresh_entities.php"
			]
		}
	}
}
```

**Hint**: The order of the runonce files is taken into account.

**Hint**: No, the installer will **not** support directories. This is just to protect unexpected behavior.
