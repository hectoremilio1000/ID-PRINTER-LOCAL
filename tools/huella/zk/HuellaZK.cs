// HuellaZK.exe: lector ZKTeco (ZK9500 y compatibles) con las MISMAS órdenes y salida JSON que FingerPrint.exe
// (DigitalPersona), para que ID-Printer lo use igual.
//
//   HuellaZK.exe list-readers
//   HuellaZK.exe enroll   --reader zk:<serie> --fingerIndex 1..4 [--samples 3] [--timeoutMs 90000]
//   HuellaZK.exe identify --reader zk:<serie> --candidatesFile <ruta> [--timeoutMs 60000] [--modo autorizar|asistencia]
//
// Plantillas: se guardan como "zk1:" + base64 de la plantilla ZKFinger 10. Así nunca se mezclan con las de
// DigitalPersona: ID-Printer solo le pasa a este programa las que empiezan con "zk1:".
//
// Necesita el ZKFinger SDK de ZKTeco instalado (controlador del lector y libzkfp.dll en System32).
// Se compila con el csc.exe de .NET Framework 4 (C# 5): ver compilar.ps1. Sin plantillas en logs.
using System;
using System.Collections;
using System.Collections.Generic;
using System.Drawing;
using System.IO;
using System.Runtime.InteropServices;
using System.Text;
using System.Threading;
using System.Web.Script.Serialization;
using System.Windows.Forms;

static class Zk
{
    [DllImport("libzkfp.dll")] public static extern int ZKFPM_Init();
    [DllImport("libzkfp.dll")] public static extern int ZKFPM_Terminate();
    [DllImport("libzkfp.dll")] public static extern int ZKFPM_GetDeviceCount();
    [DllImport("libzkfp.dll")] public static extern IntPtr ZKFPM_OpenDevice(int index);
    [DllImport("libzkfp.dll")] public static extern int ZKFPM_CloseDevice(IntPtr h);
    [DllImport("libzkfp.dll")] public static extern int ZKFPM_GetParameters(IntPtr h, int code, byte[] value, ref int size);
    [DllImport("libzkfp.dll")] public static extern int ZKFPM_GetCaptureParamsEx(IntPtr h, ref int width, ref int height, ref int dpi);
    [DllImport("libzkfp.dll")] public static extern int ZKFPM_AcquireFingerprint(IntPtr h, byte[] image, uint cbImage, byte[] template, ref int cbTemplate);
    [DllImport("libzkfp.dll")] public static extern IntPtr ZKFPM_DBInit();
    [DllImport("libzkfp.dll")] public static extern int ZKFPM_DBFree(IntPtr db);
    [DllImport("libzkfp.dll")] public static extern int ZKFPM_DBAdd(IntPtr db, int fid, byte[] template, int cb);
    [DllImport("libzkfp.dll")] public static extern int ZKFPM_DBIdentify(IntPtr db, byte[] template, int cb, ref int fid, ref int score);
    [DllImport("libzkfp.dll")] public static extern int ZKFPM_DBMatch(IntPtr db, byte[] t1, int cb1, byte[] t2, int cb2);
    [DllImport("libzkfp.dll")] public static extern int ZKFPM_DBMerge(IntPtr db, byte[] t1, byte[] t2, byte[] t3, byte[] reg, ref int cbReg);

    public const string Prefijo = "zk1:";
    public const string PrefijoLector = "zk:";

    public static string Texto(byte[] b, int n)
    {
        int fin = 0;
        while (fin < n && fin < b.Length && b[fin] != 0) fin++;
        return Encoding.ASCII.GetString(b, 0, fin).Trim();
    }

    public static string Serie(IntPtr h, int i)
    {
        byte[] b = new byte[64]; int n = b.Length;
        string s = ZKFPM_GetParameters(h, 1103, b, ref n) == 0 ? Texto(b, n) : "";
        return s.Length > 0 ? s : ("indice" + i);
    }

    public static string Nombre(IntPtr h)
    {
        byte[] b = new byte[64]; int n = b.Length;
        string s = ZKFPM_GetParameters(h, 1102, b, ref n) == 0 ? Texto(b, n) : "";
        return s.Length > 0 ? ("ZKTeco " + s) : "ZKTeco";
    }
}

static class Salida
{
    static readonly JavaScriptSerializer Js = new JavaScriptSerializer();
    public static void Json(Dictionary<string, object> d) { Console.WriteLine(Js.Serialize(d)); Console.Out.Flush(); }
    public static void Evento(string nombre) { Log("{\"eventName\":\"" + nombre + "\"}"); }
    public static void Log(string linea)
    {
        try { File.AppendAllText(@"C:\temp\fp_zk.log", DateTime.Now.ToString("s") + " " + linea + Environment.NewLine); } catch { }
    }
    public static Dictionary<string, object> D(params object[] kv)
    {
        var d = new Dictionary<string, object>();
        for (int i = 0; i + 1 < kv.Length; i += 2) d[(string)kv[i]] = kv[i + 1];
        return d;
    }
}

class Ventana : Form
{
    readonly Label titulo = new Label();
    readonly Label estado = new Label();

    public Ventana(string tituloVentana, string encabezado)
    {
        Text = tituloVentana;
        Width = 460; Height = 230;
        StartPosition = FormStartPosition.CenterScreen;
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false; MinimizeBox = false;
        TopMost = true; ShowInTaskbar = true;
        titulo.Text = encabezado;
        titulo.Font = new Font("Segoe UI", 14, FontStyle.Bold);
        titulo.SetBounds(20, 18, 410, 32);
        estado.Text = "Iniciando el lector…";
        estado.Font = new Font("Segoe UI", 11);
        estado.SetBounds(20, 62, 410, 100);
        Controls.Add(titulo); Controls.Add(estado);
        Shown += delegate { Activate(); BringToFront(); };
    }

    public void Estado(string texto)
    {
        if (IsDisposed) return;
        if (InvokeRequired) { try { BeginInvoke(new Action<string>(Estado), texto); } catch { } return; }
        estado.Text = texto;
    }

    public void Cerrar()
    {
        if (IsDisposed) return;
        if (InvokeRequired) { try { BeginInvoke(new Action(Cerrar)); } catch { } return; }
        Close();
    }
}

static class Programa
{
    static Dictionary<string, string> Args(string[] a)
    {
        var d = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        for (int i = 1; i < a.Length; i++)
            if (a[i].StartsWith("--")) { d[a[i].Substring(2)] = (i + 1 < a.Length && !a[i + 1].StartsWith("--")) ? a[++i] : "true"; }
        return d;
    }

    static string Arg(Dictionary<string, string> d, string k, string def) { string v; return d.TryGetValue(k, out v) ? v : def; }

    [STAThread]
    static int Main(string[] argv)
    {
        if (argv.Length == 0) { Salida.Json(Salida.D("ok", false, "error", "uso: list-readers | enroll | identify")); return 2; }
        string cmd = argv[0].ToLowerInvariant();
        var a = Args(argv);
        try
        {
            int r = Zk.ZKFPM_Init();
            if (r == 1) r = 0; // ZKFP_ERR_ALREADY_INIT: ya estaba iniciado, sirve igual
            if (r != 0 && cmd == "list-readers") { Salida.Json(Salida.D("ok", true, "count", 0)); return 0; }
            if (r != 0) { Salida.Json(Salida.D("ok", false, "error", "zk_init_failed", "code", r)); return 3; }
            try
            {
                if (cmd == "list-readers") return ListReaders();
                if (cmd == "enroll") return Enroll(a);
                if (cmd == "identify") return Identify(a);
                Salida.Json(Salida.D("ok", false, "error", "unknown_command"));
                return 2;
            }
            finally { Zk.ZKFPM_Terminate(); }
        }
        catch (DllNotFoundException)
        {
            // Sin el SDK de ZKTeco: para listar es simplemente "no hay lectores ZKTeco".
            if (cmd == "list-readers") { Salida.Json(Salida.D("ok", true, "count", 0)); return 0; }
            Salida.Json(Salida.D("ok", false, "error", "zk_sdk_not_installed"));
            return 3;
        }
        catch (Exception e)
        {
            Salida.Log("excepcion " + e.GetType().Name + ": " + e.Message);
            Salida.Json(Salida.D("ok", false, "error", "zk_exception", "message", e.Message));
            return 3;
        }
    }

    static int ListReaders()
    {
        int n = Zk.ZKFPM_GetDeviceCount();
        if (n < 0) n = 0;
        var lectores = new List<Dictionary<string, object>>();
        for (int i = 0; i < n; i++)
        {
            IntPtr h = Zk.ZKFPM_OpenDevice(i);
            if (h == IntPtr.Zero) continue;
            try { lectores.Add(Salida.D("readerIndex", i, "serial", Zk.PrefijoLector + Zk.Serie(h, i), "name", Zk.Nombre(h))); }
            finally { Zk.ZKFPM_CloseDevice(h); }
        }
        Salida.Json(Salida.D("ok", true, "count", lectores.Count));
        foreach (var l in lectores) Salida.Json(l);
        return 0;
    }

    /** Abre el lector pedido (zk:<serie>); si no viene o no se encuentra, el primero. */
    static IntPtr Abrir(string lector, out string serie)
    {
        serie = "";
        int n = Zk.ZKFPM_GetDeviceCount();
        IntPtr primero = IntPtr.Zero; string seriePrimero = "";
        for (int i = 0; i < n; i++)
        {
            IntPtr h = Zk.ZKFPM_OpenDevice(i);
            if (h == IntPtr.Zero) continue;
            string s = Zk.PrefijoLector + Zk.Serie(h, i);
            if (string.IsNullOrEmpty(lector) || string.Equals(s, lector, StringComparison.OrdinalIgnoreCase))
            {
                if (primero != IntPtr.Zero) Zk.ZKFPM_CloseDevice(primero);
                serie = s; return h;
            }
            if (primero == IntPtr.Zero) { primero = h; seriePrimero = s; } else Zk.ZKFPM_CloseDevice(h);
        }
        serie = seriePrimero;
        return primero;
    }

    class Captura { public byte[] Plantilla; public int Largo; }

    /** Espera un dedo hasta `hasta`. Devuelve null si se acabó el tiempo o se cerró la ventana. */
    static Captura EsperarDedo(IntPtr h, byte[] imagen, DateTime hasta, Ventana v)
    {
        while (DateTime.UtcNow < hasta && !v.IsDisposed)
        {
            byte[] tpl = new byte[2048]; int cb = tpl.Length;
            int r = Zk.ZKFPM_AcquireFingerprint(h, imagen, (uint)imagen.Length, tpl, ref cb);
            if (r == 0 && cb > 0) { Salida.Evento("finger_touch"); return new Captura { Plantilla = tpl, Largo = cb }; }
            Thread.Sleep(150);
        }
        return null;
    }

    /** Corre `trabajo` en un hilo mientras la ventana se muestra; devuelve el código de salida. */
    static int ConVentana(Ventana v, Func<int> trabajo)
    {
        int codigo = 3;
        var hilo = new Thread(delegate ()
        {
            try { codigo = trabajo(); }
            catch (Exception e)
            {
                Salida.Log("excepcion " + e.GetType().Name + ": " + e.Message);
                Salida.Json(Salida.D("ok", false, "error", "zk_exception", "message", e.Message));
                codigo = 3;
            }
            finally { Thread.Sleep(700); v.Cerrar(); }
        });
        hilo.IsBackground = true;
        v.Shown += delegate { hilo.Start(); };
        Application.EnableVisualStyles();
        Application.Run(v);
        hilo.Join(3000);
        return codigo;
    }

    static int Enroll(Dictionary<string, string> a)
    {
        int finger = int.Parse(Arg(a, "fingerIndex", "1"));
        int muestras = 3; // ZKFinger une exactamente 3 capturas
        int timeoutMs = int.Parse(Arg(a, "timeoutMs", "90000"));
        string serie;
        IntPtr h = Abrir(Arg(a, "reader", ""), out serie);
        if (h == IntPtr.Zero) { Salida.Json(Salida.D("ok", false, "error", "reader_not_found")); return 3; }
        IntPtr db = Zk.ZKFPM_DBInit();
        try
        {
            int w = 0, hh = 0, dpi = 0;
            Zk.ZKFPM_GetCaptureParamsEx(h, ref w, ref hh, ref dpi);
            byte[] imagen = new byte[Math.Max(w * hh, 300 * 400)];
            var v = new Ventana("Registro de huella", "Registrar huella (dedo " + finger + ")");
            return ConVentana(v, delegate ()
            {
                DateTime hasta = DateTime.UtcNow.AddMilliseconds(timeoutMs);
                var capturas = new List<Captura>();
                while (capturas.Count < muestras)
                {
                    v.Estado("Pon el dedo en el lector (" + (capturas.Count + 1) + " de " + muestras + ").");
                    Captura c = EsperarDedo(h, imagen, hasta, v);
                    if (c == null) { Salida.Json(Salida.D("ok", false, "error", "timeout")); return 0; }
                    if (capturas.Count > 0 && Zk.ZKFPM_DBMatch(db, capturas[0].Plantilla, capturas[0].Largo, c.Plantilla, c.Largo) <= 0)
                    {
                        v.Estado("No es el mismo dedo. Usa el mismo dedo las " + muestras + " veces.");
                        Salida.Evento("enroll_different_finger");
                        Thread.Sleep(1500);
                        continue;
                    }
                    capturas.Add(c);
                    v.Estado("Listo. Levanta el dedo…");
                    Thread.Sleep(900);
                }
                byte[] reg = new byte[2048]; int cbReg = reg.Length;
                int r = Zk.ZKFPM_DBMerge(db, capturas[0].Plantilla, capturas[1].Plantilla, capturas[2].Plantilla, reg, ref cbReg);
                if (r != 0 || cbReg <= 0) { Salida.Json(Salida.D("ok", false, "error", "enroll_merge_failed", "code", r)); return 0; }
                byte[] final = new byte[cbReg]; Array.Copy(reg, final, cbReg);
                v.Estado("✅ Huella registrada.");
                Salida.Evento("enroll_ok");
                Salida.Json(Salida.D("ok", true, "mode", "enroll", "fingerIndex", finger, "readerSerial", serie,
                    "format", "zk1", "templateBase64", Zk.Prefijo + Convert.ToBase64String(final)));
                return 0;
            });
        }
        finally { Zk.ZKFPM_DBFree(db); Zk.ZKFPM_CloseDevice(h); }
    }

    static int Identify(Dictionary<string, string> a)
    {
        int timeoutMs = int.Parse(Arg(a, "timeoutMs", "60000"));
        bool autorizar = Arg(a, "modo", "asistencia").ToLowerInvariant() == "autorizar";
        string archivo = Arg(a, "candidatesFile", "");
        if (archivo.Length == 0 || !File.Exists(archivo)) { Salida.Json(Salida.D("ok", false, "error", "candidates_file_missing")); return 3; }

        // { "candidates": [ { "userId": 1, "templates": ["zk1:...", ...] } ] } — se ignora lo que no es ZKTeco.
        var js = new JavaScriptSerializer { MaxJsonLength = int.MaxValue };
        var raiz = js.Deserialize<Dictionary<string, object>>(File.ReadAllText(archivo));
        var porFid = new Dictionary<int, int>();
        string serie;
        IntPtr h = Abrir(Arg(a, "reader", ""), out serie);
        if (h == IntPtr.Zero) { Salida.Json(Salida.D("ok", false, "error", "reader_not_found")); return 3; }
        IntPtr db = Zk.ZKFPM_DBInit();
        try
        {
            int fid = 1, usuarios = 0;
            object lista;
            if (raiz != null && raiz.TryGetValue("candidates", out lista) && lista is ArrayList)
            {
                foreach (object o in (ArrayList)lista)
                {
                    var c = o as Dictionary<string, object>;
                    if (c == null || !c.ContainsKey("userId") || !(c["templates"] is ArrayList)) continue;
                    int userId = Convert.ToInt32(c["userId"]);
                    bool alguna = false;
                    foreach (object t in (ArrayList)c["templates"])
                    {
                        string s = t as string;
                        if (s == null || !s.StartsWith(Zk.Prefijo)) continue;
                        byte[] b;
                        try { b = Convert.FromBase64String(s.Substring(Zk.Prefijo.Length)); } catch { continue; }
                        if (Zk.ZKFPM_DBAdd(db, fid, b, b.Length) == 0) { porFid[fid] = userId; fid++; alguna = true; }
                    }
                    if (alguna) usuarios++;
                }
            }
            Salida.Log("{\"eventName\":\"identify_candidates_loaded\",\"users\":" + usuarios + ",\"templates\":" + porFid.Count + "}");
            if (porFid.Count == 0) { Salida.Json(Salida.D("ok", false, "error", "no_zk_templates")); return 0; }

            int w = 0, hh = 0, dpi = 0;
            Zk.ZKFPM_GetCaptureParamsEx(h, ref w, ref hh, ref dpi);
            byte[] imagen = new byte[Math.Max(w * hh, 300 * 400)];
            var v = autorizar
                ? new Ventana("Autorizar con huella", "🔐 Autorizar acción")
                : new Ventana("Asistencia por huella", "🕒 Registro de asistencia");
            return ConVentana(v, delegate ()
            {
                DateTime hasta = DateTime.UtcNow.AddMilliseconds(timeoutMs);
                for (int intento = 1; intento <= 3; intento++)
                {
                    v.Estado(autorizar ? "Gerente: pon tu dedo en el lector para autorizar." : "Pon tu dedo en el lector para registrar tu asistencia.");
                    Captura cap = EsperarDedo(h, imagen, hasta, v);
                    if (cap == null) { Salida.Json(Salida.D("ok", false, "error", "timeout")); return 0; }
                    int f = 0, score = 0;
                    if (Zk.ZKFPM_DBIdentify(db, cap.Plantilla, cap.Largo, ref f, ref score) == 0 && porFid.ContainsKey(f))
                    {
                        v.Estado(autorizar ? "✅ Autorizado" : "✅ Asistencia registrada");
                        Salida.Log("{\"eventName\":\"identify_match\",\"score\":" + score + "}");
                        Salida.Json(Salida.D("ok", true, "mode", "identify", "matched", true, "userId", porFid[f], "score", score));
                        return 0;
                    }
                    Salida.Evento("identify_no_match");
                    v.Estado("❌ No coincide. Intento " + intento + "/3. Pon el dedo otra vez…");
                    Thread.Sleep(1200);
                }
                Salida.Json(Salida.D("ok", true, "mode", "identify", "matched", false));
                return 0;
            });
        }
        finally { Zk.ZKFPM_DBFree(db); Zk.ZKFPM_CloseDevice(h); }
    }
}
