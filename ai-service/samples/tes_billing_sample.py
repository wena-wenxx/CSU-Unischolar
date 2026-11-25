import io
import random
import sys

from PIL import Image, ImageDraw, ImageFilter, ImageFont

WIDTH, HEIGHT = 2640, 1710
LINES = (0.0, 0.0453, 0.0894, 0.1666, 0.2552, 0.3300, 0.4080, 0.4447, 0.4868, 0.6042, 0.6337,
         0.7739, 0.8295, 0.8590, 0.9019, 0.9429, 1.0)
LEFT, RIGHT = 40, 2600
ROW_H = 112
MARK = "SAMPLE – NOT AN OFFICIAL DOCUMENT – FICTIONAL PERSONS"

LAST = ["ABAD", "BAUTISTA", "CRUZ", "DELA PEÑA", "ESPINO", "FLORES", "GALANG", "HERNANDEZ", "IBAÑEZ",
        "JAVIER", "LUMBAY", "MAGNO", "NAVARRO", "OCAMPO", "PADILLA", "QUIMPO", "RAMOS", "SALAZAR",
        "TORRES", "UMALI", "VALDEZ", "YAP", "ZAMORA", "AGUILAR", "BELTRAN", "CORPUZ", "DIZON", "ESTRADA"]
FIRST = ["ANA MARIE", "BEN", "CARLO JAY", "DIANA", "ELMER", "FAITH", "GINO", "HAZEL MAE", "IVAN", "JOY",
         "KARL", "LEA", "MARK ANTHONY", "NINA", "OSCAR", "PIA", "RENZ", "SHAINA", "TROY", "VINA"]
MIDDLE = ["SANTOS", "REYES", "NA", "LOPEZ", "GARCIA", "MENDOZA", "CASTRO", "RIVERA"]
PROGRAMS = ["BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY", "BACHELOR OF SCIENCE IN AGRICULTURE",
            "BACHELOR OF SCIENCE IN CIVIL ENGINEERING", "BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS",
            "BACHELOR OF SCIENCE IN BIOLOGY", "BACHELOR OF ARTS IN SOCIOLOGY"]


def fictional_rows(count=20, seed=7):
    rnd = random.Random(seed)
    rows = []
    for n in range(1, count + 1):
        last, first = LAST[(n - 1) % len(LAST)], FIRST[rnd.randrange(len(FIRST))]
        rows.append({
            "control_number": f"{n:05d}",
            "student_id": f"{rnd.choice(['211', '221', '231'])}-0{rnd.randrange(1000, 9999)}",
            "award_number": f"TES-20990101-990000000000-{900000 + n:06d}",
            "last": last, "first": first, "middle": MIDDLE[rnd.randrange(len(MIDDLE))],
            "sex": rnd.choice(["MALE", "FEMALE"]), "birthdate": "",
            "program": PROGRAMS[rnd.randrange(len(PROGRAMS))], "year_level": str(rnd.randint(1, 4)),
            "email": f"s{n:03d}@example.com", "phone": f"0900{n:07d}",
            "batch": "2", "amount": "10,000.00", "pwd": "No", "total_amount": "10,000.00",
        })
    return rows


def _font(size):
    for name in ("DejaVuSans.ttf", "arial.ttf", "LiberationSans-Regular.ttf"):
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            pass
    return ImageFont.load_default(size=size)


def _wrap(field, value):
    if field == "student_id" and value.count("-") == 1:
        a, b = value.split("-")
        return [a + "-", b]
    if field == "award_number" and value.count("-") == 3:
        p = value.split("-")
        return [f"{p[0]}-{p[1]}-", f"{p[2]}-", p[3]]
    if field == "program" and len(value) > 24:
        words, lines, line = value.split(), [], ""
        for w in words:
            if len(line) + len(w) + 1 > 24 and line:
                lines.append(line)
                line = w
            else:
                line = (line + " " + w).strip()
        return lines + [line]
    if field == "email" and len(value) > 30:
        return [value]
    return [value] if value else []


def make_pages(rows, rows_per_page=12, tilt=0.0, split_last_row=True, seed=3, mistakes=None,
               school_year="2098-2099", campus="Sample State University-Main Campus"):
    mistakes = mistakes or {}
    rnd = random.Random(seed)
    font, small, big = _font(18), _font(17), _font(30)
    columns = ("control_number", "student_id", "award_number", "last", "first", "middle", "sex", "birthdate",
               "program", "year_level", "email", "phone", "batch", "amount", "pwd", "total_amount")
    xs = [LEFT + (RIGHT - LEFT) * p for p in LINES]
    pages = []
    chunks = [rows[i:i + rows_per_page] for i in range(0, len(rows), rows_per_page)]
    for index, chunk in enumerate(chunks):
        img = Image.new("L", (WIDTH, HEIGHT), 250)
        d = ImageDraw.Draw(img)
        words = []

        def put(x, y, text, f=font, conf=0.95):
            for k, part in enumerate(text.split(" ")):
                if not part:
                    continue
                box = d.textbbox((x, y), part, font=f)
                d.text((x, y), part, fill=20, font=f)
                words.append((box[0], box[1], box[2], box[3], part, conf))
                x = box[2] + d.textlength(" ", font=f)

        put(60, 20, MARK, small)
        top = 170
        if index == 0:
            put(900, 70, "CONSOLIDATED CONTINUING TES GRANTEES DETAILS", big)
            put(950, 120, f"TES Batch 2 - First Semester, Academic Year {school_year}", font)
            put(60, 180, f"HEI Campus Name: {campus}", font)
            top = 260
        cut = split_last_row and index < len(chunks) - 1
        y_lines = [top + k * ROW_H for k in range(len(chunk) + 1)]
        if cut:
            y_lines = y_lines[:-1]
        bottom = y_lines[-1] + (ROW_H * 2 // 3 if cut else 0)
        for y in y_lines:
            d.line([(LEFT, y), (RIGHT, y)], fill=30, width=3)
        for x in xs:
            d.line([(x, top), (x, bottom)], fill=30, width=3)
        for r, row in enumerate(chunk):
            y0 = top + r * ROW_H
            for c, field in enumerate(columns):
                value = mistakes.get((row["control_number"], field), row[field])
                lines = _wrap(field, value)
                if cut and r == len(chunk) - 1 and field == "award_number":
                    lines = lines[:2]
                for k, line in enumerate(lines):
                    put(xs[c] + 12, y0 + 12 + k * 30, line, small if field in ("email", "program") else font)
        pages.append([img, words, chunk[-1] if cut else None])

    for k in range(1, len(pages)):
        prev_row = pages[k - 1][2]
        if prev_row:
            img, words = pages[k][0], pages[k][1]
            d = ImageDraw.Draw(img)
            tail = prev_row["award_number"].split("-")[-1]
            box = d.textbbox((xs[2] + 12, 120), tail, font=font)
            d.text((xs[2] + 12, 120), tail, fill=20, font=font)
            words.append((box[0], box[1], box[2], box[3], tail, 0.95))
            for x in xs:
                d.line([(x, 100), (x, 170)], fill=30, width=3)
    last_img, last_words = pages[-1][0], pages[-1][1]
    d = ImageDraw.Draw(last_img)
    total = sum(float(r["total_amount"].replace(",", "")) for r in rows)
    y = HEIGHT - 260
    for text in (f"Total Tertiary Education Subsidy: {total:,.2f}", "Prepared by: (TES Focal)    Certified by: "
                 "(Registrar)    Certified by: (Chief Accountant)    Approved by: (University President)"):
        box = d.textbbox((600, y), text, font=font)
        d.text((600, y), text, fill=20, font=font)
        x = 600
        for part in text.split(" "):
            b = d.textbbox((x, y), part, font=font)
            last_words.append((b[0], b[1], b[2], b[3], part, 0.95))
            x = b[2] + d.textlength(" ", font=font)
        y += 60

    out = []
    for img, words, _ in pages:
        if tilt:
            img = img.rotate(tilt, resample=Image.BICUBIC, fillcolor=250, expand=False)
            words = _rotate_words(words, tilt)
        img = img.filter(ImageFilter.GaussianBlur(0.6))
        noise = Image.effect_noise(img.size, 18).convert("L")
        img = Image.blend(img, noise, 0.06)
        out.append((img.convert("RGB"), words))
    return out


def _rotate_words(words, angle):
    import math

    cx, cy = WIDTH / 2, HEIGHT / 2
    a = math.radians(-angle)
    out = []
    for x0, y0, x1, y1, text, conf in words:
        mx, my = (x0 + x1) / 2 - cx, (y0 + y1) / 2 - cy
        nx = cx + mx * math.cos(a) - my * math.sin(a)
        ny = cy + mx * math.sin(a) + my * math.cos(a)
        w, h = (x1 - x0) / 2, (y1 - y0) / 2
        out.append((nx - w, ny - h, nx + w, ny + h, text, conf))
    return out


def garble(text, seed=1):
    rnd = random.Random(seed)
    swap = {"T": "l", "E": "€", "a": "o", "0": "O", "1": "l", "2": "Z", "S": "5", "i": "!"}
    return "".join(swap[ch] if ch in swap and rnd.random() < 0.35 else ch for ch in text)


def write_pdf(path, pages, bad_layer=True):
    from reportlab.pdfgen import canvas
    from reportlab.lib.utils import ImageReader

    pw, ph = 949.68, 614.88
    c = canvas.Canvas(path, pagesize=(pw, ph))
    for img, words in pages:
        jpeg = io.BytesIO()
        img.convert("RGB").save(jpeg, "JPEG", quality=70)
        jpeg.seek(0)
        c.drawImage(ImageReader(jpeg), 0, 0, pw, ph)
        if bad_layer:
            sx, sy = pw / WIDTH, ph / HEIGHT
            t = c.beginText()
            t.setTextRenderMode(3)
            for k, (x0, y0, x1, y1, text, _conf) in enumerate(words):
                t.setFont("Helvetica", max(4, (y1 - y0) * sy * 0.9))
                t.setTextOrigin(x0 * sx, ph - y1 * sy)
                t.textOut(garble(text, k))
            c.drawText(t)
        c.showPage()
    c.save()


if __name__ == "__main__":
    out = sys.argv[1] if len(sys.argv) > 1 else "sample-tes-billing-FICTIONAL.pdf"
    write_pdf(out, make_pages(fictional_rows(30), tilt=0.3), bad_layer="--clean" not in sys.argv)
    print("Wrote", out)
