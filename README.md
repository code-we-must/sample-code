# Sample Code

This repository contains code snippets from a microservice where I previously worked.

## shipment-manager-original

This is the state of the code when I joined. The **ShipmentGateway* classes violate
most of the SOLID principles. The *ShippingGatewayFactory* class is a mess because
each new gateway class needs to be instantiated. In Symfony this means at least one
gateway class has two instances, one in the container and one from this factory.
There are currently 20 shipment gateway classes, less than 10 when I joined.

The biggest problem here is the AbstractShippingGateway. It's unnecessarily a base class when it does
not represent a hierarchy and also makes it difficult to unit test. The original creators
only use integration tests for everything, but it makes it difficult to test every code path.

## shipment-manager-refactor

This is the state of the code after I refactored the repository just last month. I'm pretty sure
there are other ways to do this, but I had limited time and at the same time need to preserve
existing functionality.

The new gateway class, *BobGoShippingGateway*, still has the same methods, but it no longer performs the work.
Instead, each method delegates tasks to their respective classes:

- `validateCredentials()` -> *BobGoValidateCredentials*
- `calculatePrice()` -> *BobGoCalculatePrice*
- `createWayBill()` -> *BobGoCreateWayBill*
- `downloadWayBill()` -> *BobGoDownloadWayBill*
- `getShipmentStatus()` -> *BobGoGetShipmentStatus*

The *ShippingGatewayFactory* class is now simpler and does not need to change whenever new
shipping gateway classes are added. The AbstractShippingGateway is still there but the methods
are accessed via a proxy which decouples the mentioned classes above. Later on, the AbstractShippingGateway
can be refactored as a trait or multiple traits.

I also separated the logic that creates API endpoint URLs and request payloads:

- *BobGoApiEndpointGenerator*
- *BobGo*PayloadAssembler*

These changes make the code compliant with SOLID principles, which makes it easier to maintain and test.

Being promoted to team lead in my last month, this was part of my effort to create a code generator that creates
boilerplate code for any new integration. The goal was to make development faster.

Lastly, this also demonstrates how I write tests.
