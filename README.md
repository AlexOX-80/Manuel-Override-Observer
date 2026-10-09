# Manuel Override Observer

Eigenständiges IP-Symcon-Modul zur zentralen Erkennung manueller Eingriffe.

## Ziel

Jedes angebundene Gerät erhält einen eindeutigen Betriebszustand:

- `AUTOMATIC`
- `MANUAL_OVERRIDE`

Der Manager ist unabhängig von einzelnen Automationen wie SmartShading und kann von mehreren Modulen gemeinsam genutzt werden.

## Erkannte Quellen

- **KNX_DEVICE**: physischer KNX-Taster oder anderer KNX-Busteilnehmer, erkannt über Gruppenadresse und physikalische Quelladresse im KNX-Gateway-Debug.
- **SYMCON_UI**: Bedienung über Symcon-Oberflächen, wenn der bestehende Aktionspfad `MOM_NotifyAction()` meldet.
- **SYMCON_AUTOMATION**: automatischer Symcon-Aufruf; wird protokolliert, erzeugt aber keinen Manual Override.
- **EXTERNAL**: explizit durch andere Module gesetzter Manual Override.

## KNX

Die aktuell aus dem vorhandenen Gateway-Dump erkannte Symcon-Quelladresse ist `15.15.241`. Andere Quelladressen werden als KNX-Geräte klassifiziert.

## Öffentliche Schnittstelle

Prefix: `MOM`

Wichtige Funktionen:

- `MOM_RegisterDevice(...)`
- `MOM_UnregisterDevice(...)`
- `MOM_NotifyAction(...)`
- `MOM_SetManualOverride(...)`
- `MOM_ClearOverride(...)`
- `MOM_ClearAllOverrides(...)`
- `MOM_GetDeviceStateJSON(...)`
- `MOM_GetAllStatesJSON(...)`
- `MOM_GetEventsJSON(...)`

## SmartShading

SmartShading erkennt eine vorhandene Instanz über die Modul-ID
`{D1E5B9B0-4D2C-4A55-94A8-0B8F6F7D219C}` und nutzt sie optional. Ist keine Instanz vorhanden, bleibt SmartShading funktionsfähig und arbeitet ohne Manual-Override-Erkennung.
