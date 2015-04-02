Your projects composer file will probably need the following 2 lines for this to install correctly:

```
    "minimum-stability": "dev",
    "prefer-stable": true
```

`$mapper` for createContact() and updateContact() looks like `mywebfield => x2fieldname` (key is MY fieldname, value is x2's fieldname like so:

```
        $mapper = [
            'First_Name' => 'firstName',
            'Last_Name' => 'lastName',
            'Primary_Email' => 'email',
        ];
```
