
# Updating your composer.json from plugin 2.x to 3.x

Version 3 of the plugin is a complete rewrite and a lot has been simplified.


## Update dependencies

In previous versions, the dependency on Contao was specified as `contao/core`. In version 3, the requirements
should be specified to be compatible with Contao 4. The basic dependency should be `contao/core-bundle`. If
you need news, you must also add `contao/news-bundle` and so on.

Be aware that you can still be compatible with Contao 3. If your extension supports Contao 3.5 and Contao 4,
 simply add a requirement for `contao/core-bundle: >=3.5,<5`.


## Always specify your sources

In previous versions of the plugin, it was possible not to specify any sources. The plugin then tried to 
guess how your plugin looks like, and simply link all files into a package name ("vendor/**name**") folder 
in `system/modules`. This is no longer supported, you **must** specify your sources or nothing will be installed.


## Upgrade your "shadow-copies" and "symlinks" maps

Older versions of the plugin supported file maps for `shadow-copies` and `symlinks` instead of just `sources`.
This was meant to allow the developer to decide the mode of installation. Support for this has been dropped in
version 3, the plugin will decide how to install the files.
