.. vim: set ts=3 sw=3 et :
.. php:namespace:: Predis

Replication
-----------

Replication in Redis allows you to configure "slave" Redis instances, serving
the same data as a single "master". Redis takes care of keeping the data on the
slaves up to date.

Predis allows you to treat groups of servers in replication as a single
aggregate connection. When you send commands to it, Predis will ensure write
commands are sent to the master, while allowing read commands to be served from
a slave.

Configuring Replication
=======================

I want to mention `Client`.

Configuring Masters and Slaves
''''''''''''''''''''''''''''''


The ``replication`` Client Option
'''''''''''''''''''''''''''''''''
.. php:namespace:: Predis\Connection

Replication and Availability
============================

Replication does not automatically increase availability.

