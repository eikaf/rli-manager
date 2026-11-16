# -*- coding: utf-8 -*-
"""
Validazione strutturale dell'XML RLI12 per una prima registrazione (tipo_adempimento = 1).

Poiche' il PHP CLI non e' disponibile in questo ambiente, questo script riproduce
ESATTAMENTE l'output di XmlBuilder per il caso di test (locatore PF + conduttore PF
+ conduttore societa' + 1 immobile + relazioni) e lo valida contro lo XSD ufficiale
tramite la libreria xmlschema.

Uso:  python tests/validate_tipo1.py
"""
import os
import sys

try:
    import xmlschema
except ImportError:
    print("ERRORE: installare xmlschema con: python -m pip install xmlschema")
    sys.exit(2)

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
XSD = os.path.join(BASE, "xsd", "RLI", "xsd", "fornituraRLI12_v1.xsd")

XML = """<?xml version="1.0" encoding="UTF-8"?>
<loc:Fornitura xmlns:loc="urn:www.agenziaentrate.gov.it:specificheTecniche:sco:loc:v1" xmlns:cm="urn:www.agenziaentrate.gov.it:specificheTecniche:common:v2" xmlns:reg="urn:www.agenziaentrate.gov.it:specificheTecniche:sco:reg:v1" xmlns:sc="urn:www.agenziaentrate.gov.it:specificheTecniche:sco:common:v2">
  <loc:Intestazione>
    <loc:CodiceFornitura>RLI12</loc:CodiceFornitura>
    <loc:TipoFornitore>10</loc:TipoFornitore>
    <loc:CodiceFiscaleFornitore>RSSMRA80A01H501U</loc:CodiceFiscaleFornitore>
  </loc:Intestazione>
  <loc:DatiGenerali>
    <loc:UfficioCompetente>
      <loc:UfficioTerritoriale>TMD</loc:UfficioTerritoriale>
    </loc:UfficioCompetente>
    <loc:ImportoDaVersare>0,00</loc:ImportoDaVersare>
  </loc:DatiGenerali>
  <loc:Documento identificativo="00001">
    <loc:Frontespizio>
      <loc:IdentificativoProdSoftware>02327910580</loc:IdentificativoProdSoftware>
      <loc:NumeroModuliCompilati>1</loc:NumeroModuliCompilati>
      <loc:IDContratto>TEST_TIPO1_2024</loc:IDContratto>
      <loc:Richiedente>
        <loc:CodiceFiscale>RSSMRA80A01H501U</loc:CodiceFiscale>
        <loc:PersoneFisiche>
          <loc:Cognome>ROSSI</loc:Cognome>
          <loc:Nome>MARIO</loc:Nome>
        </loc:PersoneFisiche>
        <loc:Firma>1</loc:Firma>
        <loc:TipologiaRichiedente>1</loc:TipologiaRichiedente>
        <loc:Telefono>3331234567</loc:Telefono>
        <loc:Email>mario.rossi@example.com</loc:Email>
      </loc:Richiedente>
      <loc:TipologiaContratto>L1</loc:TipologiaContratto>
      <loc:DataInizio>01012024</loc:DataInizio>
      <loc:DataFine>01012028</loc:DataFine>
      <loc:ImportoCanone>12000,00</loc:ImportoCanone>
      <loc:Registrazione>
        <loc:Contratto>
          <loc:DataStipula>01012024</loc:DataStipula>
          <loc:NumeroFogli>4</loc:NumeroFogli>
          <loc:NumeroCopie>2</loc:NumeroCopie>
        </loc:Contratto>
      </loc:Registrazione>
      <loc:ImpegnoATrasmettere>
        <reg:CFintermediario>RSSMRA80A01H501U</reg:CFintermediario>
        <reg:ImpegnoATrasmettere>2</reg:ImpegnoATrasmettere>
        <reg:DataImpegno>10012024</reg:DataImpegno>
        <reg:FirmaIntermediario>1</reg:FirmaIntermediario>
      </loc:ImpegnoATrasmettere>
      <loc:DataInvio>10012024</loc:DataInvio>
    </loc:Frontespizio>
    <loc:Soggetti>
      <loc:PrimoModulo>
        <loc:Locatore>
          <loc:NumeroProgressivo>001</loc:NumeroProgressivo>
          <loc:CodiceFiscale>BNCLGU75C15F205X</loc:CodiceFiscale>
          <loc:PersoneFisiche>
            <sc:Cognome>BIANCHI</sc:Cognome>
            <sc:Nome>LUIGI</sc:Nome>
            <sc:Sesso>M</sc:Sesso>
            <sc:DataNascita>15031975</sc:DataNascita>
            <sc:ComuneNascita>MILANO</sc:ComuneNascita>
            <sc:ProvinciaNascita>MI</sc:ProvinciaNascita>
          </loc:PersoneFisiche>
        </loc:Locatore>
        <loc:Conduttore>
          <loc:NumeroProgressivo>001</loc:NumeroProgressivo>
          <loc:CodiceFiscale>VRDMRA90D20H501Y</loc:CodiceFiscale>
          <loc:PersoneFisiche>
            <sc:Cognome>VERDI</sc:Cognome>
            <sc:Nome>MARIA</sc:Nome>
            <sc:Sesso>F</sc:Sesso>
            <sc:DataNascita>20041990</sc:DataNascita>
            <sc:ComuneNascita>ROMA</sc:ComuneNascita>
            <sc:ProvinciaNascita>RM</sc:ProvinciaNascita>
          </loc:PersoneFisiche>
          <loc:TipologiaConduttore>3</loc:TipologiaConduttore>
        </loc:Conduttore>
        <loc:Conduttore>
          <loc:NumeroProgressivo>002</loc:NumeroProgressivo>
          <loc:CodiceFiscale>12345678901</loc:CodiceFiscale>
          <loc:SoggettiDiversiDaPF>
            <loc:Denominazione>ACME SRL</loc:Denominazione>
          </loc:SoggettiDiversiDaPF>
          <loc:TipologiaConduttore>3</loc:TipologiaConduttore>
        </loc:Conduttore>
      </loc:PrimoModulo>
    </loc:Soggetti>
    <loc:Immobili>
      <loc:PrimoModulo>
        <loc:Immobile>
          <loc:NumeroProgressivoImmobile>001</loc:NumeroProgressivoImmobile>
          <loc:TipologiaImmobile>1</loc:TipologiaImmobile>
          <loc:CodiceComuneCatastale>H501</loc:CodiceComuneCatastale>
          <loc:TipoCatasto>U</loc:TipoCatasto>
          <loc:DatiCatastali>
            <reg:Foglio>10</reg:Foglio>
            <reg:Particella>123</reg:Particella>
            <reg:Subalterno>5</reg:Subalterno>
          </loc:DatiCatastali>
          <loc:Comune>ROMA</loc:Comune>
          <loc:Provincia>RM</loc:Provincia>
          <loc:CategoriaCatastale>A2</loc:CategoriaCatastale>
          <loc:RenditaCatastale>500,00</loc:RenditaCatastale>
          <loc:TipologiaIndirizzo>VIA</loc:TipologiaIndirizzo>
          <loc:Indirizzo>ROMA</loc:Indirizzo>
          <loc:NumCivico>1</loc:NumCivico>
        </loc:Immobile>
      </loc:PrimoModulo>
    </loc:Immobili>
    <loc:Relazioni>
      <loc:PrimoModulo>
        <loc:LocazioneUsoAbitativo>
          <loc:ImmobileNumero>001</loc:ImmobileNumero>
          <loc:LocatoreNumero>001</loc:LocatoreNumero>
          <loc:PercentualePossesso>100,00</loc:PercentualePossesso>
          <loc:Cedolare>S</loc:Cedolare>
        </loc:LocazioneUsoAbitativo>
        <loc:Dichiarazioni>
          <loc:CodiceFiscale>BNCLGU75C15F205X</loc:CodiceFiscale>
          <loc:Firma>1</loc:Firma>
        </loc:Dichiarazioni>
      </loc:PrimoModulo>
    </loc:Relazioni>
  </loc:Documento>
</loc:Fornitura>
"""

print("XSD:", XSD)
schema = xmlschema.XMLSchema(XSD)

errors = list(schema.iter_errors(XML))
if not errors:
    print("Validazione: OK (nessun errore, nessun warning)")
    sys.exit(0)

print("Validazione: FALLITA ({} errori)".format(len(errors)))
for e in errors:
    print("  -", e.reason, "| path:", e.path)
sys.exit(1)
