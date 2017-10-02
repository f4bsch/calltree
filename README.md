# calltree
Calltree is a WordPress plugin that profiles your site's performance and creates a Time Map based on components:

![Time Map](https://calltr.ee/wp-content/uploads/2017/09/time-map-1.png?v=2)

# A WordPress performance analysis tool
Calltree focuses on the time the server needs to generate the complete response (for normal page requests that's HTML, for REST&AJAX its JSON). You can call it server response time too. It can find common issues concerning PHP settings, the WordPress Object Cache, the Database and Plugins. By hooking deeply into the WordPress Plugin API, it captures hooks and function calls.


# For site admins
Easily spot plugins that slow down. The built-in issue detector gives you hints about possible performance bottlenecks and how to fix them.
It detects issues concerning:
* Plugins (load and filters)
* File system
* OPCache
* WordPress Object Cache
* Database
* Custom plugin update channels


# For plugin developers
Profiler your plugin in a real WordPress environment. The issue detector give you hints about possible solutions (such as using autoloading, if your plugin `include()`s too many files).
The profiler covers:
* Autoloading functions (registered with `spl_autoload_register()`)
* Plugin inclusion time
* Script handling
* Actions or filters your plugin hooks into



# Todo: Not yet implemented coverage/features
* Memory
* Object Cache I/O
* Database queries
* PHP included files
* Shortcodes & Meta Boxes
