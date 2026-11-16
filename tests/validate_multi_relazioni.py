# -*- coding: utf-8 -*-
"""Verifica strutturale XSD del Quadro D (Relazioni) multi-modulo con Dichiarazioni."""
import os, sys
import xmlschema

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
XSD = os.path.join(BASE, "xsd", "RLI", "xsd", "fornituraRLI12_v1.xsd")

def loc_uso(imm, locatore):
    return (
        "<loc:LocazioneUsoAbitativo>"
        f"<loc:ImmobileNumero>{imm:03d}</loc:ImmobileNumero>"
        f"<loc:LocatoreNumero>{locatore:03d}</loc:LocatoreNumero>"
        "<loc:PercentualePossesso>100,00</loc:PercentualePossesso>"
        "<loc:Cedolare>S</loc:Cedolare>"
        "</loc:LocazioneUsoAbitativo>"
    )

def dich(cf):
    return (
        "<loc:Dichiarazioni>"
        f"<loc:CodiceFiscale>{cf}</loc:CodiceFiscale>"
        "<loc:Firma>1</loc:Firma>"
        "</loc:Dichiarazioni>"
    )

# 25 righe = 5 immobili x 5 locatori
rows = [(i, l) for i in range(1, 6) for l in range(1, 6)]
modules = [rows[0:10], rows[10:20], rows[20:25]]
cf_decl = ['BNCLGU75C15F205X', 'RSSMRA80A01H501U', 'VRDMRA90D20H501Y', 'FRNGNN70A01F205X']

rel = "<loc:Relazioni>"
for m, mod in enumerate(modules):
    tag = "loc:PrimoModulo" if m == 0 else "loc:Modulo"
    rel += f"<{tag}>"
    for (imm, loc) in mod:
        rel += loc_uso(imm, loc)
    if m == 0:
        for cf in cf_decl:
            rel += dich(cf)
    rel += f"</{tag}>"
rel += "</loc:Relazioni>"

XML = f'''<?xml version="1.0" encoding="UTF-8"?>
<loc:Fornitura xmlns:loc="urn:www.agenziaentrate.gov.it:specificheTecniche:sco:loc:v1" xmlns:cm="urn:www.agenziaentrate.gov.it:specificheTecniche:common:v2" xmlns:reg="urn:www.agenziaentrate.gov.it:specificheTecniche:sco:reg:v1" xmlns:sc="urn:www.agenziaentrate.gov.it:specificheTecniche:sco:common:v2">
  <loc:Intestazione>
    <loc:CodiceFornitura>RLI12</loc:CodiceFornitura>
    <loc:TipoFornitore>10</loc:TipoFornitore>
    <loc:CodiceFiscaleFornitore>RSSMRA80A01H501U</loc:CodiceFiscaleFornitore>
  </loc:Intestazione>
  <loc:DatiGenerali>
    <loc:UfficioCompetente><loc:UfficioTerritoriale>TMD</loc:UfficioTerritoriale></loc:UfficioCompetente>
    <loc:ImportoDaVersare>0,00</loc:ImportoDaVersare>
  </loc:DatiGenerali>
  <loc:Documento identificativo="00001">
    <loc:Frontespizio>
      <loc:IdentificativoProdSoftware>02327910580</loc:IdentificativoProdSoftware>
      <loc:NumeroModuliCompilati>3</loc:NumeroModuliCompilati>
      <loc:IDContratto>TEST_MULTI</loc:IDContratto>
      <loc:Richiedente>
        <loc:CodiceFiscale>RSSMRA80A01H501U</loc:CodiceFiscale>
        <loc:PersoneFisiche><loc:Cognome>ROSSI</loc:Cognome><loc:Nome>MARIO</loc:Nome></loc:PersoneFisiche>
        <loc:Firma>1</loc:Firma>
        <loc:TipologiaRichiedente>1</loc:TipologiaRichiedente>
        <loc:Email>mario.rossi@example.com</loc:Email>
      </loc:Richiedente>
      <loc:TipologiaContratto>L1</loc:TipologiaContratto>
      <loc:DataInizio>01012024</loc:DataInizio>
      <loc:DataFine>01012028</loc:DataFine>
      <loc:ImportoCanone>24000,00</loc:ImportoCanone>
      <loc:Registrazione>
        <loc:Contratto>
          <loc:DataStipula>01012024</loc:DataStipula>
          <loc:NumeroFogli>8</loc:NumeroFogli>
          <loc:NumeroCopie>2</loc:NumeroCopie>
        </loc:Contratto>
      </loc:Registrazione>
      <loc:DataInvio>10012024</loc:DataInvio>
    </loc:Frontespizio>
    <loc:Soggetti>
      <loc:PrimoModulo>
        <loc:Locatore>
          <loc:NumeroProgressivo>001</loc:NumeroProgressivo>
          <loc:CodiceFiscale>BNCLGU75C15F205X</loc:CodiceFiscale>
          <loc:PersoneFisiche>
            <sc:Cognome>BIANCHI</sc:Cognome><sc:Nome>LUIGI</sc:Nome>
            <sc:Sesso>M</sc:Sesso><sc:DataNascita>15031975</sc:DataNascita>
            <sc:ComuneNascita>MILANO</sc:ComuneNascita><sc:ProvinciaNascita>MI</sc:ProvinciaNascita>
          </loc:PersoneFisiche>
        </loc:Locatore>
        <loc:Conduttore>
          <loc:NumeroProgressivo>001</loc:NumeroProgressivo>
          <loc:CodiceFiscale>MRNLCU75A01F205X</loc:CodiceFiscale>
          <loc:PersoneFisiche>
            <sc:Cognome>COND1</sc:Cognome><sc:Nome>NOME1</sc:Nome>
            <sc:Sesso>M</sc:Sesso><sc:DataNascita>01011975</sc:DataNascita>
            <sc:ComuneNascita>ROMA</sc:ComuneNascita><sc:ProvinciaNascita>RM</sc:ProvinciaNascita>
          </loc:PersoneFisiche>
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
            <reg:Foglio>1</reg:Foglio><reg:Particella>123</reg:Particella><reg:Subalterno>5</reg:Subalterno>
          </loc:DatiCatastali>
          <loc:Comune>ROMA</loc:Comune><loc:Provincia>RM</loc:Provincia>
          <loc:CategoriaCatastale>A2</loc:CategoriaCatastale>
          <loc:RenditaCatastale>500,00</loc:RenditaCatastale>
          <loc:TipologiaIndirizzo>VIA</loc:TipologiaIndirizzo>
          <loc:Indirizzo>ROMA</loc:Indirizzo><loc:NumCivico>1</loc:NumCivico>
        </loc:Immobile>
      </loc:PrimoModulo>
    </loc:Immobili>
    {rel}
  </loc:Documento>
</loc:Fornitura>
'''

schema = xmlschema.XMLSchema(XSD)
errors = list(schema.iter_errors(XML))
if not errors:
    print("Validazione multi-modulo Relazioni: OK (nessun errore)")
    sys.exit(0)
print(f"Validazione multi-modulo Relazioni: FALLITA ({len(errors)} errori)")
for e in errors:
    print("  -", e.reason, "| path:", e.path)
sys.exit(1)