DataSourceORM
=============

DataSourceORM is a object-relational mapper written in PHP.

**Why DataSourceORM over alternatives like Doctrine or Propel?**

The ideology behind DSORM is different to solutions like Doctrine/Propel. DSORM is designed to be zero-configuration with any given database, in the sense that all behaviour is extrapolated from your database schema at run-time.

Essentially, the advantages of DSORM are:

* You can get straight into coding - all you need to tell the ORM is the database name and connection details.
* It is easier to deploy, as long as the database can be connected to, it'll work without extra configuration.
* Clear separation of Model logic and Database logic, so you can much more easily use the same interface for your models that interact with a database layer and your models that use file IO or network connections amongst other things.
* You won't need any build scripts or automation to regenerate class definitions after schema changes or visa versa.

However, there are disadvantages:

* The database schema and class definitions are maintained separately, many ORMs let you either generate the schema from class definitions, or generate the class definitions from the schema.
* There is no independence from a database. In cases where ORMs can generate the database on the fly from a schema definition it is much easier to create independent unit tests as part of your build process or otherwise.
* Lack of knowledge about the schema's relationship with class definitions mean that many advanced features, such as automatic converting of foreign keys to the classes that correspond with the tables they have relationships with, are not possible to automate and must be coded manually (although this is not difficult, complex, or lengthy – just a simple object instantiation).
* More complex ORMs tend to provide abstractions for dealing with other types of common SQL queries, whereas DSORM does not (though a solution is provided to convert the results of one of these types of queries into an object).
