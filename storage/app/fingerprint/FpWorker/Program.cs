using System.Text;
using System.Text.Json;
using SixLabors.ImageSharp;
using SixLabors.ImageSharp.PixelFormats;
using SourceAFIS;

// ── Read stdin safely (handles large PNG payloads from PHP fwrite chunks) ─────
var sb = new StringBuilder();
using (var reader = new StreamReader(
    Console.OpenStandardInput(),
    Encoding.UTF8,
    detectEncodingFromByteOrderMarks: false,
    bufferSize: 65536))
{
    char[] buf = new char[65536];
    int    read;
    while ((read = await reader.ReadAsync(buf, 0, buf.Length)) > 0)
        sb.Append(buf, 0, read);
}
var input = sb.ToString();

Dictionary<string, JsonElement> req;
try {
    req = JsonSerializer.Deserialize<Dictionary<string, JsonElement>>(input)!;
} catch (Exception e) {
    Console.Write(JsonSerializer.Serialize(new { error = $"Bad JSON: {e.Message}" }));
    return;
}

var action = req.TryGetValue("action", out var a) ? a.GetString() ?? "" : "";
var dpi    = req.TryGetValue("dpi",    out var d) ? d.GetInt32() : 500;

// ── PNG base64 → FingerprintTemplate ─────────────────────────────────────────
FingerprintTemplate BuildTemplate(string base64Png, int imageDpi)
{
    var bytes = Convert.FromBase64String(base64Png);
    using var image = Image.Load<Rgba32>(bytes);

    var pixels = new byte[image.Width * image.Height];
    for (int y = 0; y < image.Height; y++)
        for (int x = 0; x < image.Width; x++) {
            var px = image[x, y];
            pixels[y * image.Width + x] =
                (byte)(0.299 * px.R + 0.587 * px.G + 0.114 * px.B);
        }

    var fpImage = new FingerprintImage(
        image.Width, image.Height, pixels,
        new FingerprintImageOptions { Dpi = imageDpi }
    );
    return new FingerprintTemplate(fpImage);
}

// ── ISO base64 → FingerprintTemplate (SecuGen) ───────────────────────────────
// SecuGen returns an ISO/IEC 19794-2 template — SourceAFIS cannot load this
// directly. We deserialize it manually: skip the 26-byte header, then read
// each minutia record (6 bytes each) to build a FingerprintTemplate via image.
// Since we have no image, we convert minutiae to a synthetic grayscale image.
FingerprintTemplate BuildTemplateFromIso(string base64Iso, int imageDpi)
{
    // Decode and validate minimum length
    var raw = Convert.FromBase64String(base64Iso);

    // Try loading as SourceAFIS serialized template first (in case it's already FMD)
    try {
        return new FingerprintTemplate(raw);
    } catch { }

    // Fall back: treat as ISO 19794-2 and extract minutiae manually
    if (raw.Length < 28)
        throw new Exception($"ISO template too short: {raw.Length} bytes");

    // ISO 19794-2 header: 4 magic + 2 version + 4 length + 2 captureDeviceId +
    // 1 imageAcquisitionLevel + 1 fingers + 1 scaleUnits + 2 scanResX + 2 scanResY +
    // 2 imgSizeX + 2 imgSizeY + 1 bitsPerMinutia + 1 reserved = 26 bytes
    int offset   = 26;
    int count    = raw[offset++];  // number of minutiae
    // skip quality byte if present
    if (raw.Length < 26 + 1 + count * 6)
        throw new Exception($"ISO template length mismatch: expected {26 + 1 + count * 6} got {raw.Length}");

    // Build a minimal synthetic 200x200 image with minutiae dots so SourceAFIS
    // can extract a template from it
    int W = 200, H = 200;
    var pixels = new byte[W * H];
    // Fill with mid-gray background
    Array.Fill(pixels, (byte)128);

    for (int i = 0; i < count; i++)
    {
        int b0 = raw[offset + i * 6];
        int b1 = raw[offset + i * 6 + 1];
        int b2 = raw[offset + i * 6 + 2];
        int b3 = raw[offset + i * 6 + 3];

        // X = lower 14 bits of first two bytes, Y = lower 14 bits of next two bytes
        int x = ((b0 & 0x3F) << 8 | b1) * W / 16384;
        int y = ((b2 & 0x3F) << 8 | b3) * H / 16384;

        x = Math.Clamp(x, 1, W - 2);
        y = Math.Clamp(y, 1, H - 2);

        // Draw a dark dot at each minutia position
        pixels[y * W + x]         = 30;
        pixels[(y-1) * W + x]     = 30;
        pixels[(y+1) * W + x]     = 30;
        pixels[y * W + (x-1)]     = 30;
        pixels[y * W + (x+1)]     = 30;
    }

    var fpImage = new FingerprintImage(W, H, pixels,
        new FingerprintImageOptions { Dpi = imageDpi });
    return new FingerprintTemplate(fpImage);
}

// ── Deserialize stored FMD bytes → FingerprintTemplate ───────────────────────
FingerprintTemplate LoadFmd(string base64Fmd)
{
    var bytes = Convert.FromBase64String(base64Fmd);
    return new FingerprintTemplate(bytes);
}

// ── action: extract (DigitalPersona PNG) ─────────────────────────────────────
if (action == "extract")
{
    try {
        var tpl = BuildTemplate(req["image"].GetString()!, dpi);
        var fmd = Convert.ToBase64String(tpl.ToByteArray());
        Console.Write(JsonSerializer.Serialize(new { fmd }));
    } catch (Exception e) {
        Console.Write(JsonSerializer.Serialize(new { error = e.Message }));
    }
}

// ── action: extract_iso (SecuGen ISO template) ────────────────────────────────
else if (action == "extract_iso")
{
    try {
        var tpl = BuildTemplateFromIso(req["template"].GetString()!, dpi);
        var fmd = Convert.ToBase64String(tpl.ToByteArray());
        Console.Write(JsonSerializer.Serialize(new { fmd }));
    } catch (Exception e) {
        Console.Write(JsonSerializer.Serialize(new { error = e.Message }));
    }
}

// ── action: match (DigitalPersona PNG probe) ──────────────────────────────────
else if (action == "match")
{
    try {
        var probe   = BuildTemplate(req["probe"].GetString()!, dpi);
        var matcher = new FingerprintMatcher(probe);
        var scores  = new List<object>();

        foreach (var c in req["candidates"].EnumerateArray())
        {
            double score = 0;
            try {
                score = matcher.Match(LoadFmd(c.GetProperty("fmd").GetString()!));
            } catch { }

            scores.Add(new {
                id           = c.GetProperty("id").GetInt32(),
                employid     = c.GetProperty("employid").GetString(),
                finger_index = c.GetProperty("finger_index").GetInt32(),
                score        = Math.Round(score, 4),
            });
        }

        scores.Sort((x, y) =>
            ((double)((dynamic)y).score).CompareTo((double)((dynamic)x).score));

        Console.Write(JsonSerializer.Serialize(new { scores }));

    } catch (Exception e) {
        Console.Write(JsonSerializer.Serialize(new { error = e.Message }));
    }
}

// ── action: match_iso (SecuGen ISO template probe) ───────────────────────────
else if (action == "match_iso")
{
    try {
        var probe   = BuildTemplateFromIso(req["probe"].GetString()!, dpi);
        var matcher = new FingerprintMatcher(probe);
        var scores  = new List<object>();

        foreach (var c in req["candidates"].EnumerateArray())
        {
            double score = 0;
            try {
                score = matcher.Match(LoadFmd(c.GetProperty("fmd").GetString()!));
            } catch { }

            scores.Add(new {
                id           = c.GetProperty("id").GetInt32(),
                employid     = c.GetProperty("employid").GetString(),
                finger_index = c.GetProperty("finger_index").GetInt32(),
                score        = Math.Round(score, 4),
            });
        }

        scores.Sort((x, y) =>
            ((double)((dynamic)y).score).CompareTo((double)((dynamic)x).score));

        Console.Write(JsonSerializer.Serialize(new { scores }));

    } catch (Exception e) {
        Console.Write(JsonSerializer.Serialize(new { error = e.Message }));
    }
}
// ── action: match_fmd (FMD probe → stored FMDs, fastest path) ────────────────
else if (action == "match_fmd")
{
    try {
        var probe   = LoadFmd(req["probe_fmd"].GetString()!);
        var matcher = new FingerprintMatcher(probe);
        var scores  = new List<object>();

        foreach (var c in req["candidates"].EnumerateArray())
        {
            double score = 0;
            try {
                score = matcher.Match(LoadFmd(c.GetProperty("fmd").GetString()!));
            } catch { }

            scores.Add(new {
                id           = c.GetProperty("id").GetInt32(),
                employid     = c.GetProperty("employid").GetString(),
                finger_index = c.GetProperty("finger_index").GetInt32(),
                score        = Math.Round(score, 4),
            });
        }

        scores.Sort((x, y) =>
            ((double)((dynamic)y).score).CompareTo((double)((dynamic)x).score));

        Console.Write(JsonSerializer.Serialize(new { scores }));

    } catch (Exception e) {
        Console.Write(JsonSerializer.Serialize(new { error = e.Message }));
    }
}
else
{
    Console.Write(JsonSerializer.Serialize(new { error = $"Unknown action: '{action}'" }));
}