package main

import (
	"crypto/rand"
	"crypto/sha256"
	"crypto/sha512"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"os"

	"filippo.io/edwards25519"
)

func main() {
	mode := flag.String("mode", "keygen", "keygen|derive|userhash|recover")
	masterPriv := flag.String("master-private", "", "master private scalar hex")
	uuid := flag.String("uuid", "", "user uuid for derive")
	key := flag.String("key", "", "available private key hex for userhash/recover")
	flag.Parse()

	switch *mode {
	case "keygen":
		pair, err := generateMaster()
		if err != nil {
			fatal(err)
		}
		split, err := splitPrivate(pair.priv)
		if err != nil {
			fatal(err)
		}
		out(map[string]string{
			"master_private_key":    hex.EncodeToString(pair.priv.Bytes()),
			"master_public_key":     hex.EncodeToString(pair.pub.Bytes()),
			"available_private_key": split, // sample split for convenience
		})
	case "derive":
		if *masterPriv == "" || *uuid == "" {
			fatal(fmt.Errorf("derive requires -master-private and -uuid"))
		}
		x, err := parseScalar(*masterPriv)
		if err != nil {
			fatal(err)
		}
		av, err := deriveAvailable(x, *uuid)
		if err != nil {
			fatal(err)
		}
		out(map[string]string{"available_private_key": av})
	case "userhash":
		raw, err := hex.DecodeString(*key)
		if err != nil {
			fatal(err)
		}
		sum := sha256.Sum256(raw)
		out(map[string]string{"user_hash": hex.EncodeToString(sum[:8])})
	case "recover":
		pub, err := recoverPub(*key)
		if err != nil {
			fatal(err)
		}
		out(map[string]string{"public_key": pub})
	default:
		fatal(fmt.Errorf("unknown mode %q", *mode))
	}
}

type keyPair struct {
	priv *edwards25519.Scalar
	pub  *edwards25519.Point
}

func generateMaster() (*keyPair, error) {
	var seed [64]byte
	if _, err := rand.Read(seed[:]); err != nil {
		return nil, err
	}
	x, err := edwards25519.NewScalar().SetUniformBytes(seed[:])
	if err != nil {
		return nil, err
	}
	P := new(edwards25519.Point).ScalarBaseMult(x)
	return &keyPair{priv: x, pub: P}, nil
}

func splitPrivate(x *edwards25519.Scalar) (string, error) {
	var seed [64]byte
	if _, err := rand.Read(seed[:]); err != nil {
		return "", err
	}
	r, err := edwards25519.NewScalar().SetUniformBytes(seed[:])
	if err != nil {
		return "", err
	}
	k := new(edwards25519.Scalar).Subtract(x, r)
	full := append(r.Bytes(), k.Bytes()...)
	return hex.EncodeToString(full), nil
}

func deriveAvailable(x *edwards25519.Scalar, userUUID string) (string, error) {
	msg := append([]byte("fboard-sudoku-v1"), append(x.Bytes(), []byte(userUUID)...)...)
	sum := sha512.Sum512(msg)
	r, err := edwards25519.NewScalar().SetUniformBytes(sum[:])
	if err != nil {
		return "", err
	}
	k := new(edwards25519.Scalar).Subtract(x, r)
	full := append(r.Bytes(), k.Bytes()...)
	return hex.EncodeToString(full), nil
}

func parseScalar(h string) (*edwards25519.Scalar, error) {
	b, err := hex.DecodeString(h)
	if err != nil {
		return nil, err
	}
	switch len(b) {
	case 32:
		return edwards25519.NewScalar().SetCanonicalBytes(b)
	case 64:
		r, err := edwards25519.NewScalar().SetCanonicalBytes(b[:32])
		if err != nil {
			return nil, err
		}
		k, err := edwards25519.NewScalar().SetCanonicalBytes(b[32:])
		if err != nil {
			return nil, err
		}
		return new(edwards25519.Scalar).Add(r, k), nil
	default:
		return nil, fmt.Errorf("invalid scalar length %d", len(b))
	}
}

func recoverPub(h string) (string, error) {
	x, err := parseScalar(h)
	if err != nil {
		return "", err
	}
	return hex.EncodeToString(new(edwards25519.Point).ScalarBaseMult(x).Bytes()), nil
}

func out(v any) {
	enc := json.NewEncoder(os.Stdout)
	_ = enc.Encode(v)
}

func fatal(err error) {
	fmt.Fprintln(os.Stderr, err.Error())
	os.Exit(1)
}
