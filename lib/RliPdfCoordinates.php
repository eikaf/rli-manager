<?php
/**
 * RliPdfCoordinates — Coordinate FPDI per il modello RLI (A4, 210×297 mm).
 *
 * Iterazioni:
 *  - v1-v7: fix progressivi
 *  - v8: 
 *        * C: +3mm sulla Y base (contenuto ancora sui label)
 *        * D: +3mm sulla Y base
 *        * B: numero conduttore spostato a sinistra
 *        * Dichiarazione locatori: +3mm
 */
class RliPdfCoordinates
{
    // ============================================================
    // PAGINE
    // ============================================================
    public const PAGE_PRIVACY      = 1;
    public const PAGE_FRONTESPIZIO = 2;
    public const PAGE_SOGGETTI     = 3;
    public const PAGE_IMMOBILI     = 4;
    public const PAGE_CANONI       = 5;

    // ============================================================
    // QUADRO A — FRONTESPIZIO (pag. 2) — OK
    // ============================================================
    public const A_CF_HEADER = ['x' => 82.0, 'y' => 13.0, 'step' => 5.15];
    public const A_TIPOLOGIA = ['x' => 42.0, 'y' => 65.5];
    public const A_DURATA_DAL = ['x' => 66.0, 'y' => 65.5, 'step' => 4.0, 'gap' => 2.0];
    public const A_DURATA_AL  = ['x' => 114.0, 'y' => 65.5, 'step' => 4.0, 'gap' => 2.0];
    public const A_CANONE = ['x' => 178.0, 'y' => 65.5];
    public const A_NUM_PAGINE   = ['x' => 125.0, 'y' => 116.0];
    public const A_NUM_COPIE    = ['x' => 139.0, 'y' => 116.0];
    public const A_DATA_STIPULA = ['x' => 157.0, 'y' => 116.0, 'step' => 4.0, 'gap' => 2.0];
    public const A_UFFICIO      = ['x' => 56.0, 'y' => 116.5];
    public const A_DATA_ADEMP = ['x' => 41.0, 'y' => 151.0, 'step' => 4.0, 'gap' => 2.0];
    public const A_ID_CONTRATTO_ADE = ['x' => 133.0, 'y' => 155.0];
    public const A_RIC_COGNOME    = ['x' => 38.0, 'y' => 178.0];
    public const A_RIC_NOME       = ['x' => 124.0, 'y' => 178.0];
    public const A_RIC_CF         = ['x' => 38.0, 'y' => 191.0, 'step' => 5.0];
    public const A_RIC_NUM_MODULI = ['x' => 185.0, 'y' => 14.0];

    // ============================================================
    // QUADRO B — SOGGETTI (pag. 3) — quasi perfetto
    // ============================================================

    public const B_LOC_BASE_Y = [36.5, 60.5, 84.5, 108.5];

    public const B_LOC_NUM       = ['x' => 22.0, 'y_offset' => 12.0];
    public const B_LOC_CF        = ['x' => 40.0, 'y_offset' => 0.5, 'step' => 5.15];
    public const B_LOC_COGNOME   = ['x' => 42.0,  'y_offset' => 9.5];
    public const B_LOC_NOME      = ['x' => 130.0, 'y_offset' => 9.5];
    public const B_LOC_DATA      = ['x' => 42.0, 'y_offset' => 17.5, 'step' => 4.2, 'gap' => 2.0];
    public const B_LOC_SESSO_M   = ['x' => 84.0, 'y_offset' => 16.5];
    public const B_LOC_SESSO_F   = ['x' => 88.0, 'y_offset' => 16.5];
    public const B_LOC_COMUNE    = ['x' => 100.0, 'y_offset' => 17.5];
    public const B_LOC_PROV      = ['x' => 186.0, 'y_offset' => 17.5];
    public const B_LOC_CHECK_LOC = ['x' => 156.0, 'y_offset' => -3.0];
    public const B_LOC_CHECK_IVA = ['x' => 172.0, 'y_offset' => -3.0];
    public const B_LOC_CHECK_CED = ['x' => 184.0, 'y_offset' => -3.0];
    public const B_LOC_CHECK_CES = ['x' => 195.0, 'y_offset' => -3.0];

    public const B_COND_BASE_Y = [138.5, 163.8, 189.2, 214.6];

    // FIX v8: numero conduttore a sinistra (allineato con x=8 per coerenza col template)
    // L'offset y_offset 8.0 è ok, ma noto dal PDF che finisce accanto al box.
    // Il box "NUMERO CONDUTTORE" ha il centro a circa (8, y_base+8). Il numero va scritto lì.
    public const B_COND_NUM      = ['x' => 22.0, 'y_offset' => 12.2];

    public const B_COND_CF       = ['x' => 40.0, 'y_offset' => 0.0, 'step' => 5.15];
    public const B_COND_COGNOME  = ['x' => 42.0,  'y_offset' => 8.1];
    public const B_COND_NOME     = ['x' => 130.0, 'y_offset' => 8.1];
    public const B_COND_DATA     = ['x' => 42.0, 'y_offset' => 16.6, 'step' => 4.2, 'gap' => 2.0];
    public const B_COND_SESSO_M  = ['x' => 84.0, 'y_offset' => 17.0];
    public const B_COND_SESSO_F  = ['x' => 88.0, 'y_offset' => 17.0];
    public const B_COND_COMUNE   = ['x' => 100.0, 'y_offset' => 16.6];
    public const B_COND_PROV     = ['x' => 186.0, 'y_offset' => 16.6];
    public const B_COND_TIP      = ['x' => 130.0, 'y_offset' => 0.0];
    public const B_COND_CHECK_IVA = ['x' => 172.0, 'y_offset' => -3.0];
    public const B_COND_CHECK_CED = ['x' => 184.0, 'y_offset' => -3.0];
    public const B_COND_CHECK_CES = ['x' => 195.0, 'y_offset' => -3.0];

    // ============================================================
    // QUADRO C — IMMOBILI (pag. 4, parte alta)
    // FIX v8: +3mm (v7 era +4mm dal v5, ancora contenuto sui label)
    // ============================================================
    public const C_BASE_Y = [33.5, 63.1, 92.7, 122.3];

    // Riga A (y_base + 0): tipologia, codice comune, T/U, I/P, sezione, foglio, particella
    public const C_NUM         = ['x' => 20.58, 'y_offset' => 5.84];
    public const C_TIPOLOGIA   = ['x' => 45.25, 'y_offset' => 4.43, 'step' => 5.0];
    public const C_COMUNE_CAT  = ['x' => 59.31, 'y_offset' => 5.83, 'step' => 5.13];
    public const C_TU          = ['x' => 88.59, 'y_offset' => 4.61, 'step' => 5.0];
    public const C_IP          = ['x' => 98.54, 'y_offset' => 4.61, 'step' => 5.0];
    public const C_SEZIONE     = ['x' => 106.0, 'y_offset' => 5.83, 'step' => 5.0];
    public const C_FOGLIO      = ['x' => 126.92, 'y_offset' => 5.83, 'step' => 5.1];
    public const C_PARTICELLA  = ['x' => 150.91, 'y_offset' => 5.83, 'step' => 4.94];

    // Riga B (y_base + 9): subalterno, comune, provincia
    public const C_SUBALTERNO  = ['x' => 39.43, 'y_offset' => 14.30, 'step' => 4.94];
    public const C_COMUNE      = ['x' => 77.61, 'y_offset' => 13.25];
    public const C_PROVINCIA   = ['x' => 188.66, 'y_offset' => 13.25];

    // Riga C (y_base + 20): categoria, rendita, toponimo, indirizzo, civico
    public const C_CATEGORIA   = ['x' => 39.27, 'y_offset' => 23.12, 'step' => 5.1];
    public const C_RENDITA     = ['x' => 70.82, 'y_offset' => 22.46];
    public const C_TOPONIMO    = ['x' => 90.31, 'y_offset' => 21.72];
    public const C_INDIRIZZO   = ['x' => 117.48, 'y_offset' => 21.72];
    public const C_CIVICO      = ['x' => 192.30, 'y_offset' => 21.83];

    public const C_CHECK_ACCATASTAMENTO = ['x' => 68.0, 'y_offset' => 9.0];

    // ============================================================
    // QUADRO D — REGIME DI TASSAZIONE (pag. 4, parte bassa)
    // FIX v8: +3mm
    // ============================================================
    public const D_BASE_Y_SX = [160.7, 173.4, 186.1, 198.8, 211.5];
    public const D_BASE_Y_DX = [160.7, 173.4, 186.1, 198.8, 211.5];

    public const D_COL_SX = 50.74;
    public const D_COL_DX = 124.81;
    public const D_OFF_IMM    = 0.0;
    public const D_OFF_LOC    = 15.0;
    public const D_OFF_QUOTA  = 36.0;
    public const D_OFF_CED_SI = 53.6;
    public const D_OFF_CED_NO = 57.9;

    // Dichiarazione locatori: +3mm, con spacing che sta entro 297mm
    public const D_DICH_BASE_Y = [229.5, 242.2, 254.9, 267.6];
    public const D_DICH_CF     = ['x' => 38.34, 'step' => 5.0];
    public const D_DICH_FIRMA  = ['x' => 120.0];

    // ============================================================
    // QUADRO E — CANONI VARIABILI (pag. 5)
    // ============================================================
    public const E_BASE_Y_SX = [32.91, 45.61, 58.31, 71.01];
    public const E_BASE_Y_DX = [32.91, 45.61, 58.31, 71.01];
    public const E_COL_SX    = 47.0;
    public const E_COL_DX    = 152.0;
    public const E_IMPORTO_X_SX = 67.0;
    public const E_IMPORTO_X_DX = 148.0;
    public const E_IMPORTO_Y_OFFSET = 6.76;
    public const E_LABEL_X_SX = 47.0;
    public const E_LABEL_X_DX = 152.0;
    public const E_LABEL_Y_OFFSET = -3.0;
    // ============================================================
    // QUADRO A — SEZIONE II: ADEMPIMENTI SUCCESSIVI (per tipi 2-8)
    // Coordinate estratte dal PDF AdE compilato (subentro/risoluzione/proroga)
    // ============================================================
    public const A2_ADEMP_TIPOLOGIA    = ['x' => 39.41, 'y' => 140.90];
    public const A2_TIPOLOGIA_PROROGA  = ['x' => 50.84, 'y' => 140.90];
    public const A2_TIPOLOGIA_SUBENTRO = ['x' => 68.34, 'y' => 140.90];
    public const A2_TIPOLOGIA_REGIME   = ['x' => 80.00, 'y' => 140.90];
    public const A2_CDC                = ['x' => 97.28, 'y' => 142.00];
    public const A2_TARDIVITA          = ['x' => 110.14, 'y' => 140.90];
    public const A2_ANNUALITA          = ['x' => 125.31, 'y' => 153.68, 'step' => 4.94];
    public const A2_DATA_FINE          = ['x' => 39.24, 'y' => 153.68, 'step' => 4.94, 'gap' => 2.0];
    public const A2_DATA_INIZIO_RINEG  = ['x' => 140.48, 'y' => 153.68, 'step' => 4.94, 'gap' => 2.0];
    public const A2_ID_TELEMATICO      = ['x' => 109.70, 'y' => 153.68, 'step' => 5.19];
    public const A2_COGNOME_RIC        = ['x' => 43.04, 'y' => 178.22];
    public const A2_NOME_RIC           = ['x' => 124.53, 'y' => 178.22];
    public const A2_CF_RIC             = ['x' => 38.39, 'y' => 191.78, 'step' => 5.19];
    public const A2_NUM_MODULI         = ['x' => 130.43, 'y' => 191.00];

}
