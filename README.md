<h1> X2 Rest Client</h1>

Add the following lines to your composer file:
```
    {
      "minimum-stability": "dev",
      "prefer-stable": true,
      "require": {
        "guzzlehttp/guzzle": "~6.0",
        "oca/x2restclient": "dev-master"
      }
    }
```
Make sure you run composer install. Watch out for update, as it will update all other composer stuff also.

Here is a basic example of how to create a contact:
```
<?php
// require the composer autoload file
require __DIR__ . '/vendor/autoload.php';

// set the rest client
use Oca\X2RestClient\Client as X2RestClient;

// set your env variables to craete the clietn object.
$client = new X2RestClient(getenv('X2_URL'), getenv('X2_USER'), getenv('X2_KEY'));

// set the new contacts info
$data = array('firstName' => 'test', 'lastName' => 'contact', 'email' => 'whatever@gmail.com');

// create a contact and dump the result
$contact = $client->createContact($data);
var_dump($contact);  // this will contain the new contacts info you just created.

?>
```



`$mapper` for createContact() and updateContact() looks like `mywebfield => x2fieldname` (key is MY fieldname, value is x2's fieldname like so:

```
    $mapper = [
        'First_Name' => 'firstName',
        'Last_Name' => 'lastName',
        'Primary_Email' => 'email',
    ];
```

