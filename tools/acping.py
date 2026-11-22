import socket, struct, sys, time

host, port = sys.argv[1], int(sys.argv[2])
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
s.settimeout(2)

for i in range(10):
    t = time.perf_counter() * 1000
    s.sendto(b"\x05\x00" + struct.pack("<d", t), (host, port))
    try:
        data, _ = s.recvfrom(64)
        rtt = time.perf_counter() * 1000 - t
        print(f"respuesta tipo {data[0]:#04x}, {len(data)} bytes: {rtt:.1f} ms")
    except socket.timeout:
        print("sin respuesta")
    time.sleep(0.5)
