using System.Text.Json;
using SixLabors.ImageSharp;
using SixLabors.ImageSharp.PixelFormats;
using SourceAFIS;

var input = await Console.In.ReadToEndAsync();

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

// ── Deserialize stored FMD bytes → FingerprintTemplate ────────────────────────
FingerprintTemplate LoadFmd(string base64Fmd)
{
    var bytes = Convert.FromBase64String(base64Fmd);
    return new FingerprintTemplate(bytes);   // ← correct constructor
}

// ── action: extract ───────────────────────────────────────────────────────────
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

// ── action: match ─────────────────────────────────────────────────────────────
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
else
{
    Console.Write(JsonSerializer.Serialize(new { error = $"Unknown action: '{action}'" }));
}