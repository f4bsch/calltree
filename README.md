# calltree
It's a WordPress plugin that profiles your Site and creates a Time Map:

![Time Map](https://calltr.ee/wp-content/uploads/2017/09/time-map.png?v=2)

# A WordPress performance analysis tool
Calltree focuses on the server response time (or page generation time, however you call it). It can find common issues concerning the Cache, the Database, plugin loading. By hooking deeply into the WordPress Plugin API, it captures hooks and function calls.

# ... for site admins
Spot plugins that slow down. The built-in issue detector gives you hints about possible performance bottlenecks and how to fix them.
It detects
* Slow plugins
* File system
* OPCache
* Object Cache read & write
* Database
* Custom plugin update channels



# ... for plugin developers
Profiler your plugin in a real WordPress environment. The issue detector give you hints about possible solutions (such as using autoloading, if your plugin `include()`s too many files).
* Profile your autoloaders
* Profile plugin inclusion time
* Profile actions or filters your plugin hooks into
* Profile your script handling
