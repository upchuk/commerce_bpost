# Commerce Bpost

This module integrates with the BPost shipping manager and provides a commerce shipping method and checkout pane.

By default, the main module provides one service integration, for home delivery. It supports both national and international addresses.

## How to use

1. Create a shipping method that uses the Bpost plugin

On this shipping method you need to configure the API connection to the BPost shipping manager.

Moreover, you can choose which service to use and configure each service. By default, there is home delivery (delivery to a given address).

For each service you choose, you need to specify the shipping rates (including international).

Do note that you need to configure your store and specify which countries it should ship to (Belgium + any other country). Once countries are enabled, they will be available in the shipping method configuration for
configuring the shipping rates.

2. Configure your checkout flow to use the Bpost shipping pane instead of the default one

This pane will present you with the choice between the available service plugins to choose from.


## Commerce Bpost Pickup

This submodule provides another service plugin that allows to ship to a Bpost office, post point or parcel distributor.

It integrates, and depends on, [Leaflet](https://www.drupal.org/project/leaflet) for printing the map so make sure you add the dependency to your `composer.json`.

Once enabled, the shipping method can be configured for the shipping rates as well, including the types of pickup points that can be used.
